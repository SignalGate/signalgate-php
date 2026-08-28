<?php

declare(strict_types=1);

namespace SignalGate\Tests\Support;

/**
 * One POST captured by {@see FakeTransport}. Mirrors
 * `backend-node-sdk/test/helpers.ts::RecordedRequest` one-to-one.
 *
 * `body` is the *array* the SDK handed the transport. Byte-parity assertions
 * re-encode it through `SignalGate\Wire::encodeBody()`.
 */
final class RecordedRequest
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly string $url,
        public readonly array $body,
        public readonly int $timeoutMs,
        public readonly string $requestId,
        public readonly string $idempotencyKey,
    ) {
    }
}
