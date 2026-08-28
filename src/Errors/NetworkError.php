<?php

declare(strict_types=1);

namespace SignalGate\Errors;

/**
 * Raised for DNS/TCP/TLS/I-O failures that occur before an HTTP response is
 * received (BACKEND_SDK_SPEC §6.1, §6.2).
 */
final class NetworkError extends SignalGateError
{
}
