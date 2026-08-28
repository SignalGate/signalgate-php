<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\Errors\NetworkError;
use SignalGate\Errors\ServerError;
use SignalGate\Errors\TimeoutError;
use SignalGate\Tests\Support\CapturingLogger;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;
use SignalGate\Tests\Support\RecordingSleeper;

/**
 * Group H — `flush()` / drain semantics and the §6.3 retry ladder (§A7).
 *
 * Covers AC39, AC40, AC41, AC42, AC43, AC44, AC45.
 */
final class FlushTest extends TestCase
{
    /** AC39 — one buffered event is delivered to `/v0/log` at `log_timeout_ms`. */
    public function testAc39FlushDeliversBufferedEvent(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $client->log(Fixtures::sampleEvent());
        $client->flush();

        self::assertSame(1, $fake->count());
        self::assertSame('https://api.signalgate.ai/v0/log', $fake->requests[0]->url);
        self::assertSame(1000, $fake->requests[0]->timeoutMs);
        self::assertSame(1, $client->metrics()->get('log_sent_total'));

        // Buffer is empty afterwards: another flush delivers nothing.
        $client->flush();
        self::assertSame(1, $fake->count());
    }

    /**
     * AC40 (§6.3, §5.1) — a 5xx is retried and the second attempt wins. The
     * `X-Request-Id` is FRESH per attempt (server log correlation) while the
     * `Idempotency-Key` is STABLE across the logical call (server dedup).
     */
    public function testAc40RetriesOnFiveHundredThenSucceeds(): void
    {
        $attempt = 0;
        $sleeper = new RecordingSleeper();
        $fake = new FakeTransport(static function (RecordedRequest $r) use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                throw new ServerError(503, 'UNAVAILABLE', 'try later', $r->requestId);
            }

            return Fixtures::logSuccessResponse($r);
        });
        $client = new Client(Fixtures::options(['transport' => $fake, 'sleeper' => $sleeper]));

        $client->log(Fixtures::sampleEvent());
        $client->flush();

        self::assertSame(2, $fake->count());
        self::assertSame(1, $client->metrics()->get('log_http_error_total', ['status' => '503']));
        self::assertSame(1, $client->metrics()->get('log_sent_total'));
        self::assertSame(0, $client->metrics()->get('log_dropped_total', ['reason' => 'retry_exhausted']));

        self::assertNotSame(
            $fake->requests[0]->requestId,
            $fake->requests[1]->requestId,
            'X-Request-Id must be regenerated on every attempt',
        );
        self::assertSame(
            $fake->requests[0]->idempotencyKey,
            $fake->requests[1]->idempotencyKey,
            'Idempotency-Key must be reused verbatim across retries',
        );
        self::assertSame([200], $sleeper->sleeps);
    }

    /** AC41 (§6.3, §A7) — a 4xx is DROPPED immediately, never retried. */
    public function testAc41FourXxIsDroppedWithoutRetry(): void
    {
        $logger = new CapturingLogger();
        $sleeper = new RecordingSleeper();
        $fake = new FakeTransport(static function (RecordedRequest $r): never {
            throw new ServerError(400, 'BAD_REQUEST', 'malformed event', $r->requestId);
        });
        $client = new Client(Fixtures::options([
            'transport' => $fake,
            'logger' => $logger,
            'sleeper' => $sleeper,
        ]));

        $client->log(Fixtures::sampleEvent());
        $client->flush();

        self::assertSame(1, $fake->count());
        self::assertSame(1, $client->metrics()->get('log_http_error_total', ['status' => '400']));
        self::assertSame(1, $client->metrics()->get('log_dropped_total', ['reason' => 'retry_exhausted']));
        self::assertSame(0, $client->metrics()->get('log_sent_total'));
        self::assertSame([], $sleeper->sleeps, 'no backoff for a non-retryable 4xx');
        self::assertNotSame([], $logger->atLevel('warn'));
    }

    /**
     * AC42 (§6.3) — the full ladder at the §A2 defaults: 4 attempts total
     * (`log_max_retries` 3 + 1), backing off EXACTLY 200 / 400 / 800 ms, with
     * no sleep after the final attempt.
     */
    public function testAc42BackoffLadderIsExactly200Then400Then800(): void
    {
        $sleeper = new RecordingSleeper();
        $fake = new FakeTransport(static function (RecordedRequest $r): never {
            throw new ServerError(503, 'UNAVAILABLE', 'still down', $r->requestId);
        });
        $client = new Client(Fixtures::options(['transport' => $fake, 'sleeper' => $sleeper]));

        $client->log(Fixtures::sampleEvent());
        $client->flush();

        self::assertSame(4, $fake->count(), 'log_max_retries = 3 => 4 attempts');
        self::assertSame([200, 400, 800], $sleeper->sleeps);

        self::assertCount(4, array_unique($fake->requestIds()), 'a fresh X-Request-Id per attempt');
        self::assertCount(1, array_unique($fake->idempotencyKeys()), 'one Idempotency-Key per logical call');

        self::assertSame(4, $client->metrics()->get('log_http_error_total', ['status' => '503']));
        self::assertSame(1, $client->metrics()->get('log_dropped_total', ['reason' => 'retry_exhausted']));
        self::assertSame(0, $client->metrics()->get('log_sent_total'));
    }

    /**
     * AC43 (§6.3) — timeouts and network faults share the `"network"` status
     * bucket and follow the same ladder.
     */
    public function testAc43TimeoutAndNetworkErrorsUseTheNetworkBucket(): void
    {
        $throwers = [
            'TimeoutError' => static function (RecordedRequest $r): never {
                throw new TimeoutError('deadline exceeded');
            },
            'NetworkError' => static function (RecordedRequest $r): never {
                throw new NetworkError('connection refused');
            },
        ];

        foreach ($throwers as $label => $handler) {
            $sleeper = new RecordingSleeper();
            $fake = new FakeTransport($handler);
            $client = new Client(Fixtures::options(['transport' => $fake, 'sleeper' => $sleeper]));

            $client->log(Fixtures::sampleEvent());
            $client->flush();

            self::assertSame(4, $fake->count(), "{$label}: 4 attempts");
            self::assertSame([200, 400, 800], $sleeper->sleeps, "{$label}: ladder");
            self::assertSame(
                4,
                $client->metrics()->get('log_http_error_total', ['status' => 'network']),
                "{$label}: bucketed as network",
            );
            self::assertSame(
                1,
                $client->metrics()->get('log_dropped_total', ['reason' => 'retry_exhausted']),
                "{$label}: dropped after the ladder",
            );
            self::assertSame(0, $client->metrics()->get('log_sent_total'), "{$label}: nothing sent");
        }
    }

    /**
     * AC44 (§A7 robustness) — a job that throws something OUTSIDE the SDK's
     * taxonomy must not kill the drain: it is bucketed as `network` and later
     * jobs still get delivered.
     */
    public function testAc44DrainSurvivesArbitraryThrowables(): void
    {
        $calls = 0;
        $sleeper = new RecordingSleeper();
        $fake = new FakeTransport(static function (RecordedRequest $r) use (&$calls) {
            $calls++;
            if ($calls <= 2) {
                throw new \RuntimeException('kaboom');
            }

            return Fixtures::logSuccessResponse($r);
        });
        $client = new Client(Fixtures::options(['transport' => $fake, 'sleeper' => $sleeper]));

        foreach (['A', 'B', 'C'] as $seq) {
            $client->log(Fixtures::sampleEvent(['custom' => ['seq' => $seq]]));
        }

        $client->flush(); // must not throw

        self::assertSame(2, $client->metrics()->get('log_http_error_total', ['status' => 'network']));
        self::assertSame(3, $client->metrics()->get('log_sent_total'));
        self::assertSame(3, $client->metrics()->get('log_enqueued_total'));
        self::assertSame(5, $fake->count(), '2 failed attempts + 3 successful deliveries');
    }

    /** AC45 — `flush()` is idempotent and a flush of an empty buffer is a no-op. */
    public function testAc45FlushIsIdempotent(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $client->log(Fixtures::sampleEvent());
        $client->flush();
        $client->flush();
        $client->flush();

        self::assertSame(1, $fake->count());
        self::assertSame(1, $client->metrics()->get('log_sent_total'));

        $emptyFake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $emptyClient = new Client(Fixtures::options(['transport' => $emptyFake]));
        $emptyClient->flush();

        self::assertSame(0, $emptyFake->count());
        self::assertSame(0, $emptyClient->metrics()->get('log_sent_total'));
    }
}
