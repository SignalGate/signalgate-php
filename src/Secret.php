<?php

declare(strict_types=1);

namespace SignalGate;

/**
 * A dump-proof holder for the raw `api_key` (BACKEND_SDK_SPEC §8.4;
 * see AC60–AC62 in tests/EdgeCases/ApiKeyTraceLeakTest.php).
 *
 * The wrapped string is never stored as an ordinary instance property: any
 * such property is fully visible to `print_r()`, `var_dump()`, `var_export()`
 * and `serialize()` — `private` only hides it from ordinary PHP code, not
 * from the dump helpers, and a live `Client`/`CurlTransport` argument sitting
 * in `Exception::getTrace()` hands those helpers the object directly.
 *
 * Instead the string lives in a `static` side-table keyed by object identity
 * (a `\WeakMap`, so an unreferenced `Secret` — and its entry — is collected
 * normally). The instance itself carries no real properties for a dumper to
 * render, `__serialize()` emits nothing, and `__debugInfo()` reports only a
 * redacted placeholder.
 */
final class Secret
{
    /** @var \WeakMap<self, string> */
    private static \WeakMap $values;

    public function __construct(string $value)
    {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this];
    }

    /** @return array<never, never> Nothing to serialize — the key must not survive it (AC61). */
    public function __serialize(): array
    {
        return [];
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): void
    {
        self::$values ??= new \WeakMap();
        self::$values[$this] = '';
    }

    /** @return array<string, string> A redacted placeholder for var_dump() (AC60). */
    public function __debugInfo(): array
    {
        return ['value' => '***REDACTED***'];
    }
}
