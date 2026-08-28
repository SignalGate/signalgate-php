<?php

declare(strict_types=1);

namespace SignalGate\Errors;

/**
 * Raised for invalid SDK configuration, a malformed caller event, or use of
 * a closed {@see \SignalGate\Client} (BACKEND_SDK_SPEC §6.2, §A10).
 */
final class ConfigError extends SignalGateError
{
}
