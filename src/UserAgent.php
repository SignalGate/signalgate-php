<?php

declare(strict_types=1);

namespace SignalGate;

/**
 * Builds the SDK's `User-Agent` header value (BACKEND_SDK_SPEC §8.6,
 * PORTING_SPEC §A4). Never takes the API key — the header is composed
 * independently by the transport (§8.4 redaction).
 *
 * The shape is the family's, byte-for-byte identical in structure to the Go,
 * Node, Python and Java ports: the `{SDK_NAME}/{SDK_VERSION}` prefix is the
 * contract (the server groups traffic by it) and the parenthetical is
 * informational.
 */
final class UserAgent
{
    /**
     * @return string e.g. `"signalgate-backend-sdk/0.1.0 (php/8.3.6; darwin)"`
     */
    public static function build(): string
    {
        return sprintf(
            '%s/%s (php/%s; %s)',
            Constants::SDK_NAME,
            Constants::SDK_VERSION,
            PHP_VERSION,
            strtolower(PHP_OS_FAMILY),
        );
    }
}
