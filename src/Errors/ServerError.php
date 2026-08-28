<?php

declare(strict_types=1);

namespace SignalGate\Errors;

/**
 * Raised for a non-2xx response carrying the §5.3 server error envelope
 * (BACKEND_SDK_SPEC §6.2, §A10).
 *
 * PHP's `Exception::getMessage()` is `final`, so it cannot be overridden;
 * instead the formatted `"[<statusCode>] <code>: <message>"` string is
 * passed to the parent constructor, and the raw server text is additionally
 * exposed via the readonly `$serverMessage` property.
 *
 * NOTE: per-property `public readonly` (PHP 8.1), never `readonly class`.
 */
final class ServerError extends SignalGateError
{
    public readonly int $statusCode;

    /**
     * NOT `readonly`, NOT typed: PHP's built-in `\Exception::$code` is
     * declared untyped, and a redeclared property must match that exactly
     * (an added type — let alone `readonly`, which requires a type — is a
     * hard PHP language conflict, not an implementation choice). Written
     * exactly once, in the constructor below, and never mutated afterward;
     * callers should treat it as a string.
     *
     * @var string
     */
    public $code;

    public readonly string $serverMessage;

    public readonly string $requestId;

    /** @var array<string, mixed>|null */
    public readonly ?array $details;

    /**
     * @param array<string, mixed>|null $details `null` when the server
     *                                            envelope omits it — never
     *                                            defaulted to `[]`.
     */
    public function __construct(
        int $statusCode,
        string $code,
        string $serverMessage,
        string $requestId,
        ?array $details = null,
    ) {
        parent::__construct("[{$statusCode}] {$code}: {$serverMessage}");

        $this->statusCode = $statusCode;
        $this->code = $code;
        $this->serverMessage = $serverMessage;
        $this->requestId = $requestId;
        $this->details = $details;
    }
}
