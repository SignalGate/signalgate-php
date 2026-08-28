<?php

declare(strict_types=1);

namespace SignalGate;

/**
 * The parsed `/v0/check` verdict (BACKEND_SDK_SPEC §4.2).
 *
 * NOTE: per-property `public readonly` (PHP 8.1), never `readonly class`
 * (8.2+), because the package floor is PHP 8.1.
 */
final class CheckResult
{
    public function __construct(
        public readonly string $action,
        public readonly float $score,
        public readonly string $requestId,
        public readonly string $tenantId,
        public readonly string $timestamp,
        public readonly int $processingTimeUs,
        public readonly bool $failedOpen,
    ) {
    }
}
