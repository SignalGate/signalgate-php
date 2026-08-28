<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\Errors\ServerError;
use SignalGate\Metrics;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;
use SignalGate\Tests\Support\RecordingSleeper;

/**
 * Group I — shutdown / `close()` (§3.1, §A8).
 *
 * Covers AC46, AC47, AC48.
 */
final class ShutdownTest extends TestCase
{
    /**
     * AC46 (§9 row 10, §A8) — THE CONSERVATION INVARIANT:
     * `log_enqueued_total == log_sent_total + sum(log_dropped_total{*})`
     * after `close()`, across a 100-event burst, on both the all-succeed and
     * the all-fail path.
     */
    public function testAc46CloseConservesEveryEnqueuedEvent(): void
    {
        // Path 1: the transport always acknowledges.
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $client = new Client(Fixtures::options([
            'transport' => $fake,
            'log_queue_capacity' => 200,
            'sleeper' => new RecordingSleeper(),
        ]));

        for ($i = 0; $i < 100; $i++) {
            $client->log(Fixtures::sampleEvent(['custom' => ['seq' => $i]]));
        }
        $client->close();

        self::assertSame(100, $client->metrics()->get('log_enqueued_total'));
        self::assertSame(100, self::accountedFor($client->metrics()));
        self::assertSame(100, $client->metrics()->get('log_sent_total'));

        // Path 2: the transport always 500s and retries are disabled.
        $failing = new FakeTransport(static function (RecordedRequest $r): never {
            throw new ServerError(500, 'INTERNAL', 'boom', $r->requestId);
        });
        $doomed = new Client(Fixtures::options([
            'transport' => $failing,
            'log_queue_capacity' => 200,
            'log_max_retries' => 0,
            'sleeper' => new RecordingSleeper(),
        ]));

        for ($i = 0; $i < 100; $i++) {
            $doomed->log(Fixtures::sampleEvent(['custom' => ['seq' => $i]]));
        }
        $doomed->close();

        self::assertSame(100, $doomed->metrics()->get('log_enqueued_total'));
        self::assertSame(100, self::accountedFor($doomed->metrics()));
        self::assertSame(
            100,
            $doomed->metrics()->get('log_dropped_total', ['reason' => 'retry_exhausted']),
        );
        self::assertSame(0, $doomed->metrics()->get('log_sent_total'));
        self::assertSame(100, $failing->count(), 'log_max_retries = 0 => exactly one attempt each');
    }

    /**
     * AC47 (§A8) — `close()` is IDEMPOTENT and the drain fires exactly once.
     * This is the guard that makes the FPM `fastcgi_finish_request()` +
     * `register_shutdown_function` double-entry safe.
     *
     * Also §A8: the SDK closes only a transport it OWNS, never an injected one.
     */
    public function testAc47CloseIsIdempotentAndNeverClosesAnInjectedTransport(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $client->log(Fixtures::sampleEvent());

        $client->close();
        $callsAfterFirstClose = $fake->count();
        $snapshotAfterFirstClose = $client->metrics()->snapshotFlat();

        $client->close();
        $client->close();

        self::assertSame(1, $callsAfterFirstClose);
        self::assertSame(1, $fake->count(), 'the 2nd and 3rd close() must add no transport calls');
        self::assertSame(1, $client->metrics()->get('log_sent_total'));
        self::assertSame(
            $snapshotAfterFirstClose,
            $client->metrics()->snapshotFlat(),
            'repeat close() must leave every counter untouched',
        );

        self::assertFalse($fake->closed, 'an INJECTED transport is never closed by the SDK');
    }

    /**
     * AC48 (§3.1 deadline) — a zero deadline drains nothing and counts the
     * remainder as `log_dropped_total{reason="closed"}`; the default deadline
     * is generous enough to drain the whole buffer.
     *
     * DEVIATION (see report): the manifest also asks to assert the default
     * deadline is literally `5 x log_timeout_ms`. That constant is only
     * observable by letting a drain overrun a wall-clock deadline, which needs
     * real sleeping — banned by the test surface (the test surface: injected sleeper,
     * no `usleep`) and inherently flaky. The OBSERVABLE consequences of the
     * deadline parameter are asserted instead.
     */
    public function testAc48CloseDeadlineIsHonoured(): void
    {
        // Zero deadline: nothing is delivered, everything is accounted as closed.
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $client = new Client(Fixtures::options([
            'transport' => $fake,
            'sleeper' => new RecordingSleeper(),
        ]));

        for ($i = 0; $i < 5; $i++) {
            $client->log(Fixtures::sampleEvent(['custom' => ['seq' => $i]]));
        }
        $client->close(0.0);

        self::assertSame(0, $fake->count(), 'a zero deadline leaves no time to deliver anything');
        self::assertSame(5, $client->metrics()->get('log_dropped_total', ['reason' => 'closed']));
        self::assertSame(0, $client->metrics()->get('log_sent_total'));
        self::assertSame(5, $client->metrics()->get('log_enqueued_total'));
        self::assertSame(5, self::accountedFor($client->metrics()));

        // Default deadline: the whole buffer drains, nothing is dropped as closed.
        $roomy = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $patient = new Client(Fixtures::options([
            'transport' => $roomy,
            'sleeper' => new RecordingSleeper(),
        ]));

        for ($i = 0; $i < 5; $i++) {
            $patient->log(Fixtures::sampleEvent(['custom' => ['seq' => $i]]));
        }
        $patient->close();

        self::assertSame(5, $roomy->count());
        self::assertSame(5, $patient->metrics()->get('log_sent_total'));
        self::assertSame(0, $patient->metrics()->get('log_dropped_total', ['reason' => 'closed']));
        self::assertSame(5, self::accountedFor($patient->metrics()));
    }

    /** `log_sent_total + sum(log_dropped_total{*})` — the §A8 right-hand side. */
    private static function accountedFor(Metrics $metrics): int
    {
        return $metrics->get('log_sent_total')
            + $metrics->get('log_dropped_total', ['reason' => 'queue_full'])
            + $metrics->get('log_dropped_total', ['reason' => 'closed'])
            + $metrics->get('log_dropped_total', ['reason' => 'retry_exhausted']);
    }
}
