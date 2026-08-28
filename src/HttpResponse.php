<?php

declare(strict_types=1);

namespace SignalGate;

/**
 * A 2xx response from {@see Transport::post()} (PORTING_SPEC §A3 response
 * envelope). A body that will not JSON-decode becomes `[]`, never an exception.
 *
 * Part of the frozen seam: the test suite's scripted handlers construct these.
 *
 * NOTE: per-property `public readonly` (PHP 8.1), never `readonly class`.
 */
final class HttpResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $body,
        public readonly string $requestId = '',
    ) {
    }
}
