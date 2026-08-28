<?php

declare(strict_types=1);

namespace SignalGate;

use SignalGate\Errors\NetworkError;
use SignalGate\Errors\ServerError;
use SignalGate\Errors\TimeoutError;

/**
 * The production HTTP transport (BACKEND_SDK_SPEC §8.1 in cURL terms, PHP
 * addendum §2). One persistent `curl_init()` handle per {@see Client}, reset
 * with `curl_reset()` before every request and closed once via {@see close()}.
 *
 * SECURITY (§8.4, addendum §7): cURL's verbose-debug-output option is NEVER
 * enabled here — doing so would echo the `Authorization` header (and
 * therefore the API key) to stderr. AC54 statically scans every shipped
 * `src/` file to guarantee that option's constant name appears nowhere.
 *
 * The api_key is held as a {@see Secret}, never as a plain string property
 * (BACKEND_SDK_SPEC §8.4): the raw value is only revealed for the
 * instant it takes to compose the `Authorization` header inside {@see post()}.
 */
final class CurlTransport implements Transport
{
    /** @var \CurlHandle|null|resource */
    private $handle;

    private bool $closed = false;

    public function __construct(private readonly Secret $apiKey)
    {
        $this->handle = curl_init();
    }

    public function post(string $url, array $body, PostOptions $options): HttpResponse
    {
        curl_reset($this->handle);

        $headers = self::buildHeaders($this->apiKey->reveal(), $options);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TIMEOUT_MS => $options->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $options->timeoutMs,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => Wire::encodeBody($body),
            CURLOPT_HTTPHEADER => $headerLines,
        ];

        if (defined('CURL_HTTP_VERSION_2TLS')) {
            $curlOptions[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }

        curl_setopt_array($this->handle, $curlOptions);

        $raw = curl_exec($this->handle);
        $errno = curl_errno($this->handle);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new TimeoutError("signalgate: request to {$url} timed out after {$options->timeoutMs}ms");
        }

        if ($errno !== 0) {
            $curlError = curl_error($this->handle);

            throw new NetworkError("signalgate: network error posting to {$url}: {$curlError}");
        }

        $statusCode = (int) curl_getinfo($this->handle, CURLINFO_HTTP_CODE);

        /** @var array<string, mixed>|null $decoded */
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return new HttpResponse($statusCode, $decoded, $options->requestId);
        }

        $error = $decoded['error'] ?? [];
        $error = is_array($error) ? $error : [];

        throw new ServerError(
            $statusCode,
            (string) ($error['code'] ?? 'UNKNOWN_ERROR'),
            (string) ($error['message'] ?? ''),
            (string) ($error['request_id'] ?? $options->requestId),
            is_array($error['details'] ?? null) ? $error['details'] : null,
        );
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        // Since PHP 8.0 a CurlHandle is an object freed by refcount, making
        // curl_close() a no-op — and PHP 8.5 deprecates calling it at all.
        // Dropping our reference is what actually releases the handle, and it
        // does so on every supported version; calling curl_close() below 8.5
        // would add nothing but a second way to say the same thing.
        $this->handle = null;
        $this->closed = true;
    }

    /**
     * Composes the exact five §5.1/§A4 request headers. Public and static so
     * it can be exercised without a real network call (AC15, AC53).
     *
     * SECURITY (§8.4): the raw `$apiKey` must appear in NO header value other
     * than `Authorization`.
     *
     * @return array<string, string>
     */
    public static function buildHeaders(string $apiKey, PostOptions $options): array
    {
        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => UserAgent::build(),
            'X-Request-Id' => $options->requestId,
            'Idempotency-Key' => $options->idempotencyKey,
        ];
    }
}
