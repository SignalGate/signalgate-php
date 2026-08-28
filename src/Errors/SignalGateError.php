<?php

declare(strict_types=1);

namespace SignalGate\Errors;

/**
 * Root of the SDK's error hierarchy (BACKEND_SDK_SPEC §6.2, §A10). Every
 * error the SDK can throw is a `SignalGateError`, so `catch (SignalGateError)`
 * catches all of them with a single clause.
 */
class SignalGateError extends \RuntimeException
{
}
