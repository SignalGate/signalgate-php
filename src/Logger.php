<?php

declare(strict_types=1);

namespace SignalGate;

/**
 * Structured-logging seam (BACKEND_SDK_SPEC §7). Default implementation is a
 * no-op. Levels in use: `debug` (request/response — API key redacted),
 * `warn` (retries, fail-open), `error` (queue full, invalid event).
 *
 * Part of the frozen seam: the test suite's `CapturingLogger` implements it.
 *
 * SECURITY (§8.4): implementations of the SDK MUST NEVER pass the raw API key
 * into any of these calls; an `Authorization` value that is logged must read
 * `Bearer ***REDACTED***`.
 */
interface Logger
{
    /** @param array<string, mixed> $fields */
    public function debug(string $message, array $fields = []): void;

    /** @param array<string, mixed> $fields */
    public function info(string $message, array $fields = []): void;

    /** @param array<string, mixed> $fields */
    public function warn(string $message, array $fields = []): void;

    /** @param array<string, mixed> $fields */
    public function error(string $message, array $fields = []): void;
}
