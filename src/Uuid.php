<?php

declare(strict_types=1);

namespace SignalGate;

/**
 * Zero-dependency UUIDv4 generation (BACKEND_SDK_SPEC §5.1, §A4 — used for
 * both `X-Request-Id` and `Idempotency-Key`).
 */
final class Uuid
{
    /**
     * @return string a version-4, variant-1 UUID, e.g.
     *                 `"3fa85f64-5717-4562-b3fc-2c963f66afa6"`
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);

        // Version 4: set the four most significant bits of byte 6 to 0100.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // Variant 1 (RFC 4122): set the two most significant bits of byte 8 to 10.
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
