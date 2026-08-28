<?php

declare(strict_types=1);

namespace SignalGate;

/**
 * SDK identity, wire endpoints, and configuration defaults
 * (BACKEND_SDK_SPEC §2; PORTING_SPEC §A2).
 *
 * PHP class constants must be constant expressions, so they cannot call
 * `getenv()`. The dev-only `SIGNALGATE_BASE_URL` escape hatch (§A2 —
 * "read once at class-load, hidden, never a constructor option") is
 * therefore exposed via {@see self::baseUrl()} / {@see self::checkUrl()} /
 * {@see self::logUrl()} instead of a `const`. Within a single PHP process
 * the resolved value is stable (memoized on first read); later env changes
 * in the SAME process need not be observed — the frozen `AC3` probe spawns a
 * fresh child process per scenario specifically to sidestep this.
 */
final class Constants
{
    /**
     * The FAMILY-wide SDK name, shared verbatim by every port (Go, Node,
     * Python, Java, PHP). §8.6 requires the `{SDK_NAME}/{SDK_VERSION}` prefix
     * verbatim because the server groups traffic by it; §A4 pins the literal.
     * The language is carried in the parenthetical, not the name.
     */
    public const SDK_NAME = 'signalgate-backend-sdk';

    public const SDK_VERSION = '0.1.0';

    public const API_VERSION = 'v0';

    /** Production default; overridable only via `SIGNALGATE_BASE_URL` (§A2). */
    public const DEFAULT_BASE_URL = 'https://api.signalgate.ai';

    public const DEFAULT_CHECK_TIMEOUT_MS = 3000;

    public const DEFAULT_LOG_TIMEOUT_MS = 1000;

    public const DEFAULT_LOG_QUEUE_CAPACITY = 10000;

    public const DEFAULT_LOG_MAX_RETRIES = 3;

    public const DEFAULT_LOG_RETRY_BASE_MS = 200;

    /** Memoized resolved base URL; `null` until first read. */
    private static ?string $resolvedBaseUrl = null;

    /**
     * Resolve the base URL once (memoized): `SIGNALGATE_BASE_URL` with
     * trailing slashes stripped, falling back to {@see self::DEFAULT_BASE_URL}.
     */
    public static function baseUrl(): string
    {
        if (self::$resolvedBaseUrl === null) {
            $env = getenv('SIGNALGATE_BASE_URL');
            $base = ($env !== false && $env !== '') ? $env : self::DEFAULT_BASE_URL;
            self::$resolvedBaseUrl = rtrim($base, '/');
        }

        return self::$resolvedBaseUrl;
    }

    public static function checkUrl(): string
    {
        return self::baseUrl() . '/' . self::API_VERSION . '/check';
    }

    public static function logUrl(): string
    {
        return self::baseUrl() . '/' . self::API_VERSION . '/log';
    }
}
