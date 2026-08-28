<?php

declare(strict_types=1);

namespace SignalGate;

/** Default {@see Logger} implementation: every call is a no-op. */
final class NoopLogger implements Logger
{
    public function debug(string $message, array $fields = []): void
    {
    }

    public function info(string $message, array $fields = []): void
    {
    }

    public function warn(string $message, array $fields = []): void
    {
    }

    public function error(string $message, array $fields = []): void
    {
    }
}
