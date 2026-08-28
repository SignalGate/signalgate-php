<?php

declare(strict_types=1);

namespace SignalGate\Errors;

/**
 * Raised when a request exceeds its configured timeout
 * (BACKEND_SDK_SPEC §6.1, §6.2).
 */
final class TimeoutError extends SignalGateError
{
}
