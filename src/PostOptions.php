<?php

declare(strict_types=1);

namespace SignalGate;

/**
 * Per-attempt request options handed to {@see Transport::post()}
 * (PORTING_SPEC §A4).
 *
 * `requestId`   — fresh UUIDv4 per HTTP attempt (regenerated on every retry).
 * `idempotencyKey` — UUIDv4 stable across all retries of one logical call.
 *
 * Part of the frozen seam: the test suite reads these three properties off
 * every recorded request.
 *
 * NOTE: per-property `public readonly` (PHP 8.1), never `readonly class`
 * (8.2+), because the package floor is PHP 8.1.
 */
final class PostOptions
{
    public function __construct(
        public readonly int $timeoutMs,
        public readonly string $requestId,
        public readonly string $idempotencyKey,
    ) {
    }
}
