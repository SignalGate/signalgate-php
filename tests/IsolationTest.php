<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;

/**
 * Group K (isolation half) — no global / process-wide state (§8.3) and zero
 * coupling to the frontend SDK (§8.7).
 *
 * Covers AC55, AC56.
 */
final class IsolationTest extends TestCase
{
    /**
     * AC55 (§8.3) — "Two Clients with two different API keys in the same
     * process must not interfere." Everything lives on the instance: buffers,
     * counters and transports are all per-Client.
     */
    public function testAc55TwoClientsShareNothing(): void
    {
        $transportA = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));
        $transportB = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::logSuccessResponse($r));

        $a = new Client(Fixtures::options([
            'api_key' => 'key-for-tenant-a',
            'transport' => $transportA,
            'log_queue_capacity' => 5,
        ]));
        $b = new Client(Fixtures::options([
            'api_key' => 'key-for-tenant-b',
            'transport' => $transportB,
            'log_queue_capacity' => 7,
        ]));

        $a->log(Fixtures::sampleEvent(['user_id' => 'u_one']));
        $b->log(Fixtures::sampleEvent(['user_id' => 'u_two']));

        $a->flush();

        self::assertSame(1, $transportA->count());
        self::assertSame('u_one', $transportA->requests[0]->body['user_id']);
        self::assertSame(0, $transportB->count(), "A's flush must not drain B's buffer");
        self::assertSame(1, $a->metrics()->get('log_sent_total'));
        self::assertSame(0, $b->metrics()->get('log_sent_total'));
        self::assertSame(1, $a->metrics()->get('log_enqueued_total'));
        self::assertSame(1, $b->metrics()->get('log_enqueued_total'));

        $b->flush();

        self::assertSame(1, $transportB->count());
        self::assertSame('u_two', $transportB->requests[0]->body['user_id']);
        self::assertSame(1, $transportA->count(), "B's flush must not re-send A's events");
        self::assertSame(1, $a->metrics()->get('log_sent_total'), "A's counters must not move when B acts");
        self::assertSame(1, $b->metrics()->get('log_sent_total'));
    }

    /**
     * AC56 (§8.7) — zero dependency on the frontend SDK: no import, no bundled
     * types, no shared code. The two SDKs only agree on the wire format of the
     * `payload` object. And the package declares NO runtime dependency beyond
     * the language and two core extensions.
     */
    public function testAc56NoFrontendCouplingAndNoRuntimeDependencies(): void
    {
        $sources = Fixtures::shippedSources();
        self::assertNotSame([], $sources, 'src/ must contain PHP sources to scan');

        foreach ($sources as $file) {
            self::assertStringNotContainsString(
                'frontend-js-sdk',
                (string) file_get_contents($file),
                "{$file} must not reference the frontend SDK",
            );
        }

        /** @var array{require?: array<string, string>} $composer */
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('require', $composer);
        $required = array_keys($composer['require']);
        sort($required);

        self::assertSame(['ext-curl', 'ext-json', 'php'], $required);
    }
}
