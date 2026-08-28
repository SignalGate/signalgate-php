<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\Errors\ServerError;
use SignalGate\Errors\TimeoutError;
use SignalGate\HttpResponse;
use SignalGate\Metrics;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;
use SignalGate\Tests\Support\RecordingSleeper;

/**
 * Group J — metrics (§7, §A9). Metric names, label keys and label VALUES are
 * contract: tenant dashboards break if they drift between ports.
 *
 * Covers AC49, AC50.
 */
final class MetricsTest extends TestCase
{
    /**
     * AC49 (§7) — after a scripted run touching every path, the counter name
     * set is EXACTLY the eight §A9 names, no more and no fewer.
     *
     * DEVIATION (see report): names are read off `snapshotFlat()` (whose
     * `name{label="value"}` rendering AC50 pins) rather than off
     * `snapshot()`'s keys, which are an implementation-private encoding the
     * freedom ledger leaves to the implementer.
     */
    public function testAc49ExactCounterNameSet(): void
    {
        $client = self::scriptedRun();
        $flat = $client->metrics()->snapshotFlat();

        $names = [];
        foreach (array_keys($flat) as $key) {
            $names[] = explode('{', $key, 2)[0];
        }
        $names = array_values(array_unique($names));
        sort($names);

        self::assertSame([
            'check_error_total',
            'check_failed_open_total',
            'check_success_total',
            'check_total',
            'log_dropped_total',
            'log_enqueued_total',
            'log_http_error_total',
            'log_sent_total',
        ], $names);

        // Label VALUES are strings (§A9): `'503'`, never `503`.
        self::assertArrayHasKey('log_http_error_total{status="503"}', $flat);
        self::assertArrayHasKey('check_error_total{type="TimeoutError"}', $flat);
        self::assertArrayHasKey('log_dropped_total{reason="queue_full"}', $flat);
        self::assertArrayHasKey('log_dropped_total{reason="retry_exhausted"}', $flat);
        self::assertArrayHasKey('log_dropped_total{reason="closed"}', $flat);

        // The raw snapshot exposes the same set of counters.
        self::assertCount(count($flat), $client->metrics()->snapshot());
    }

    /** AC50 (§A9) — flat rendering, zero default, label-order insensitivity. */
    public function testAc50FlatSnapshotAndCounterIdentity(): void
    {
        $client = self::scriptedRun();
        $flat = $client->metrics()->snapshotFlat();

        self::assertSame(1, $flat['log_dropped_total{reason="queue_full"}']);
        self::assertSame(1, $flat['log_http_error_total{status="503"}']);
        self::assertSame(2, $flat['check_total']);

        // `get()` on a never-incremented counter is 0, never null.
        self::assertSame(0, $client->metrics()->get('log_http_error_total', ['status' => '418']));
        self::assertSame(0, $client->metrics()->get('nonexistent_total'));

        // A counter is keyed by name + the SET of label pairs: call-time order
        // is irrelevant to identity, so these two increments sum into one.
        $metrics = new Metrics();
        $metrics->inc('x_total', ['a' => '1', 'b' => '2']);
        $metrics->inc('x_total', ['b' => '2', 'a' => '1']);

        self::assertSame(2, $metrics->get('x_total', ['a' => '1', 'b' => '2']));
        self::assertSame(2, $metrics->get('x_total', ['b' => '2', 'a' => '1']));
        self::assertSame(['x_total{a="1",b="2"}' => 2], $metrics->snapshotFlat());
    }

    /**
     * One client driven through every counter-bearing path: a successful
     * check, a failed-open check, a queue-full eviction, a 5xx drop, a
     * successful delivery, and a close-with-zero-deadline drop.
     */
    private static function scriptedRun(): Client
    {
        $checks = 0;
        $logs = 0;

        $fake = new FakeTransport(static function (RecordedRequest $r) use (&$checks, &$logs) {
            if (str_ends_with($r->url, '/check')) {
                $checks++;
                if ($checks === 1) {
                    return new HttpResponse(200, Fixtures::goldenCheckEnvelope(), 'req_1');
                }

                throw new TimeoutError('simulated timeout');
            }

            $logs++;
            if ($logs === 1) {
                throw new ServerError(503, 'UNAVAILABLE', 'try later', $r->requestId);
            }

            return Fixtures::logSuccessResponse($r);
        });

        $client = new Client(Fixtures::options([
            'transport' => $fake,
            'sleeper' => new RecordingSleeper(),
            'log_queue_capacity' => 2,
            'log_max_retries' => 0,
        ]));

        $client->check(Fixtures::sampleEvent());   // check_total, check_success_total
        $client->check(Fixtures::sampleEvent());   // check_error_total{TimeoutError}, check_failed_open_total

        $client->log(Fixtures::sampleEvent());     // log_enqueued_total x3,
        $client->log(Fixtures::sampleEvent());     // log_dropped_total{queue_full} x1
        $client->log(Fixtures::sampleEvent());
        $client->flush();                          // log_http_error_total{503} + dropped{retry_exhausted}
                                                   // then log_sent_total

        $client->log(Fixtures::sampleEvent());     // two more buffered...
        $client->log(Fixtures::sampleEvent());
        $client->close(0.0);                       // ...dropped as log_dropped_total{closed}

        return $client;
    }
}
