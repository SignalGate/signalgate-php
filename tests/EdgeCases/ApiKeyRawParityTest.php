<?php

declare(strict_types=1);

namespace SignalGate\Tests\EdgeCases;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalGate\CurlTransport;
use SignalGate\PostOptions;

/**
 * Hardening amendment — the api_key travels RAW (§5.1, §A4).
 *
 * `Client` validates the key by trimming it, and the Node and Python ports do
 * the same. What they do NOT do is send the trimmed value: the key that goes
 * out in `Authorization` is the caller's bytes, exactly as given. A key whose
 * surrounding whitespace is significant to the issuer would authenticate on
 * Node and Python and 401 here — a silent, per-tenant outage.
 *
 * DEVIATION FROM THE APPROVED MANIFEST (recorded deliberately):
 * only the header-composition arm below is testable. The Client hands the key
 * to the transport it constructs and exposes it NOWHERE else, so "the Client
 * retains the raw key, not `trim($key)`" has no observable that avoids both a
 * real network call and reflection on private state. It cannot even be
 * observed through a dump, because AC62 of this same amendment freezes the
 * opposite requirement — the key must not be recoverable from the object at
 * all. The Client-level half of this criterion is therefore enforced by
 * implementation review, not by this suite; this test pins the seam where the
 * key is still observable, so no "fix" can re-introduce trimming there.
 *
 * Covers AC66.
 */
final class ApiKeyRawParityTest extends TestCase
{
    /**
     * AC66 (§5.1, §A4) — `Authorization` is `'Bearer ' . $key` with the
     * caller's bytes verbatim: no trimming, no collapsing, no normalization.
     */
    #[DataProvider('provideRawKeys')]
    public function testAc66TheKeyReachesTheAuthorizationHeaderByteForByte(string $apiKey): void
    {
        $headers = CurlTransport::buildHeaders(
            $apiKey,
            new PostOptions(3000, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'ffffffff-1111-4222-9333-444444444444'),
        );

        self::assertSame('Bearer ' . $apiKey, $headers['Authorization']);

        foreach (['Content-Type', 'User-Agent', 'X-Request-Id', 'Idempotency-Key'] as $name) {
            self::assertStringNotContainsString($apiKey, $headers[$name], "{$name} must never carry the key");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideRawKeys(): iterable
    {
        yield 'leading and trailing spaces' => [' pk pad '];
        yield 'inner whitespace' => ["pk\tinner"];
        yield 'plain' => ['pk_live_9f8e7d6c'];
    }
}
