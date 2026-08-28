<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\Errors\NetworkError;
use SignalGate\Tests\Support\CapturingLogger;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;
use SignalGate\Tests\Support\RecordingSleeper;

/**
 * Group G — `log()` buffer semantics (§3.3, PHP addendum §3).
 *
 * The one architectural divergence from the sibling ports: PHP-FPM has no
 * threads or event loop, so `log()` ONLY appends to a bounded in-memory
 * buffer. Delivery happens in `flush()` / `close()` / the shutdown hook.
 *
 * Covers AC35, AC36, AC37, AC38.
 */
final class LogBufferTest extends TestCase
{
    /**
     * AC35 (§9 row 5, §A14 row 7) — `log()` performs NO I/O and returns
     * essentially instantly. "Just send it inline, it's fast" is the tempting
     * wrong move the addendum forbids.
     */
    public function testAc35LogDoesNoIoAndReturnsImmediately(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));
        $event = Fixtures::sampleEvent();

        // Warm the autoloader/JIT so the measured call times the SDK, not PHP.
        $client->log($event);

        $started = hrtime(true);
        $returned = $client->log($event);
        $elapsedNs = hrtime(true) - $started;

        self::assertNull($returned);
        self::assertSame(0, $fake->count(), 'log() must not touch the transport');
        self::assertLessThan(1_000_000, $elapsedNs, 'log() must return in well under 1 ms');
        self::assertSame(2, $client->metrics()->get('log_enqueued_total'));
    }

    /** AC36 (§3.3) — `log()` NEVER throws, on any input, with any transport. */
    public function testAc36LogNeverThrows(): void
    {
        $exploding = new FakeTransport(static function (RecordedRequest $r): never {
            throw new NetworkError('every call fails');
        });
        $client = new Client(Fixtures::options([
            'transport' => $exploding,
            'sleeper' => new RecordingSleeper(),
        ]));

        $client->log(Fixtures::sampleEvent());
        self::assertSame(1, $client->metrics()->get('log_enqueued_total'));

        // A malformed event is a silent no-op: an `error` log line, no counter,
        // no throw (§3.3 "never throws on the hot path" is absolute, and not
        // counting it keeps the §A8 conservation invariant exact).
        $logger = new CapturingLogger();
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $strict = new Client(Fixtures::options(['transport' => $fake, 'logger' => $logger]));

        $malformed = Fixtures::sampleEvent();
        unset($malformed['payload']);
        $strict->log($malformed);

        self::assertSame(0, $strict->metrics()->get('log_enqueued_total'));
        self::assertSame(0, $fake->count());
        self::assertStringContainsString('invalid_event', implode("\n", $logger->messagesAtLevel('error')));
    }

    /**
     * AC37 (addendum §3.1) — overflow is DROP-OLDEST, and the evicted record
     * still counts as enqueued so §A8's conservation identity stays exact.
     */
    public function testAc37OverflowDropsOldestAndPreservesFifoOrder(): void
    {
        $logger = new CapturingLogger();
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $client = new Client(Fixtures::options([
            'transport' => $fake,
            'logger' => $logger,
            'log_queue_capacity' => 2,
            'sleeper' => new RecordingSleeper(),
        ]));

        foreach (['A', 'B', 'C'] as $seq) {
            $client->log(Fixtures::sampleEvent(['custom' => ['seq' => $seq]]));
        }
        $client->flush();

        self::assertSame(2, $fake->count());
        self::assertSame(
            ['B', 'C'],
            array_map(
                static fn (RecordedRequest $r) => $r->body['custom']['seq'],
                $fake->requests,
            ),
            'the OLDEST record is evicted and FIFO order survives',
        );

        $metrics = $client->metrics();
        $enqueued = $metrics->get('log_enqueued_total');
        $sent = $metrics->get('log_sent_total');
        $queueFull = $metrics->get('log_dropped_total', ['reason' => 'queue_full']);

        self::assertSame(3, $enqueued);
        self::assertSame(1, $queueFull);
        self::assertSame(2, $sent);
        self::assertSame(
            $enqueued,
            $sent + $queueFull
                + $metrics->get('log_dropped_total', ['reason' => 'closed'])
                + $metrics->get('log_dropped_total', ['reason' => 'retry_exhausted']),
            '§A8 conservation: enqueued == sent + sum(dropped)',
        );

        self::assertNotSame([], $logger->atLevel('error'), '§7: eviction logs at `error`');

        // Control: the SAME configuration without overflow logs no error at all
        // (so the assertion above has teeth - it cannot be satisfied by an
        // implementation that just logs errors unconditionally).
        $quietLogger = new CapturingLogger();
        $quietFake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $quiet = new Client(Fixtures::options([
            'transport' => $quietFake,
            'logger' => $quietLogger,
            'log_queue_capacity' => 2,
            'sleeper' => new RecordingSleeper(),
        ]));
        $quiet->log(Fixtures::sampleEvent(['custom' => ['seq' => 'A']]));
        $quiet->log(Fixtures::sampleEvent(['custom' => ['seq' => 'B']]));
        $quiet->flush();

        self::assertSame([], $quietLogger->atLevel('error'));
        self::assertSame(0, $quiet->metrics()->get('log_dropped_total', ['reason' => 'queue_full']));
    }

    /** AC38 (§A6) — after `close()`, `log()` is a SILENT no-op. */
    public function testAc38LogAfterCloseIsASilentNoop(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $client->log(Fixtures::sampleEvent());
        $client->close();

        $callsAfterClose = $fake->count();
        $enqueuedAfterClose = $client->metrics()->get('log_enqueued_total');

        $client->log(Fixtures::sampleEvent());

        self::assertSame($enqueuedAfterClose, $client->metrics()->get('log_enqueued_total'));
        self::assertSame($callsAfterClose, $fake->count());

        // close() is idempotent (§A8), so a second one proves nothing new was buffered.
        $client->close();
        self::assertSame($callsAfterClose, $fake->count());
    }
}
