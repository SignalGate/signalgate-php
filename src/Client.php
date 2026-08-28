<?php

declare(strict_types=1);

namespace SignalGate;

use SignalGate\Errors\ConfigError;
use SignalGate\Errors\NetworkError;
use SignalGate\Errors\ServerError;
use SignalGate\Errors\SignalGateError;
use SignalGate\Errors\TimeoutError;

/**
 * The SDK facade (BACKEND_SDK_SPEC §3.1/§3.2/§3.3; PORTING_SPEC §A2).
 *
 * `check()` is the synchronous, fail-open, zero-retry antifraud gate (§3.2,
 * §6.1, §6.3). `log()` is a synchronous, zero-I/O, never-throwing append to a
 * bounded in-memory buffer (§3.3); delivery happens later, in `flush()`,
 * `close()`, or the `register_shutdown_function` hook.
 *
 * The {@see LogBuffer} this Client owns is constructed LAZILY, on first use
 * by `log()`/`flush()`/`close()`: a Client that only ever calls `check()`
 * never touches the log path at all, which keeps `check()`-only integrations
 * fully independent of the log buffer's retry/backoff machinery. There is
 * still exactly one `LogBuffer` per `Client` (§8.3 / AC55) — construction is
 * merely deferred, not duplicated.
 */
final class Client
{
    /** @var list<string> §4.1 required top-level event keys, checked in order. */
    private const REQUIRED_EVENT_KEYS = ['user_id', 'ip', 'method', 'timestamp', 'payload'];

    /** @var list<string> options validated as non-negative integers (§A2). */
    private const NUMERIC_OPTIONS = [
        'check_timeout_ms',
        'log_timeout_ms',
        'log_queue_capacity',
        'log_max_retries',
        'log_retry_base_ms',
    ];

    /** @var list<string> every recognized constructor option key (§A2). */
    private const RECOGNIZED_OPTIONS = [
        'api_key',
        'check_timeout_ms',
        'log_timeout_ms',
        'log_queue_capacity',
        'log_max_retries',
        'log_retry_base_ms',
        'fail_open',
        'transport',
        'logger',
        'sleeper',
        'register_shutdown',
    ];

    private readonly Secret $apiKey;

    private readonly int $checkTimeoutMs;

    private readonly int $logTimeoutMs;

    private readonly int $logQueueCapacity;

    private readonly int $logMaxRetries;

    private readonly int $logRetryBaseMs;

    private readonly bool $failOpen;

    private readonly Transport $transport;

    /** Whether this Client constructed its own transport (and so must close it). */
    private readonly bool $transportOwned;

    private readonly Logger $logger;

    /** @var callable(int): void */
    private $sleeper;

    private readonly Metrics $metrics;

    private ?LogBuffer $logBuffer = null;

    private bool $closed = false;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        $this->apiKey = new Secret(self::validateApiKey($options));
        self::validateNumericOptions($options);
        self::validateUnknownKeys($options);

        $this->checkTimeoutMs = $options['check_timeout_ms'] ?? Constants::DEFAULT_CHECK_TIMEOUT_MS;
        $this->logTimeoutMs = $options['log_timeout_ms'] ?? Constants::DEFAULT_LOG_TIMEOUT_MS;
        $this->logQueueCapacity = $options['log_queue_capacity'] ?? Constants::DEFAULT_LOG_QUEUE_CAPACITY;
        $this->logMaxRetries = $options['log_max_retries'] ?? Constants::DEFAULT_LOG_MAX_RETRIES;
        $this->logRetryBaseMs = $options['log_retry_base_ms'] ?? Constants::DEFAULT_LOG_RETRY_BASE_MS;
        $this->failOpen = (bool) ($options['fail_open'] ?? true);
        $this->logger = $options['logger'] ?? new NoopLogger();
        $this->sleeper = $options['sleeper'] ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->metrics = new Metrics();

        if (isset($options['transport'])) {
            $this->transport = $options['transport'];
            $this->transportOwned = false;
        } else {
            $this->transport = new CurlTransport($this->apiKey);
            $this->transportOwned = true;
        }

        if ((bool) ($options['register_shutdown'] ?? true)) {
            register_shutdown_function(function (): void {
                $this->close();
            });
        }
    }

    /**
     * §3.2 — synchronous, fail-open, ZERO retries.
     *
     * @param array<string, mixed> $event
     *
     * @throws ConfigError the Client is closed, `$event` is missing a
     *                      required top-level key, or `$event['payload']` is
     *                      present but not an array.
     * @throws ServerError  a 4xx response from the server (never swallowed
     *                      by fail-open — it is a caller/config bug), or any
     *                      response class when `fail_open` is `false`.
     * @throws TimeoutError|NetworkError when `fail_open` is `false`. Any
     *                      transport failure OUTSIDE the named taxonomy
     *                      (BACKEND_SDK_SPEC §6.1/§6.2) is first
     *                      classified as a `NetworkError` so it still flows
     *                      through this same branching — `check()` never
     *                      lets a raw `\Throwable` escape.
     */
    public function check(array $event): CheckResult
    {
        if ($this->closed) {
            throw new ConfigError('cannot call check() on a closed SignalGate\\Client');
        }

        $missing = $this->firstMissingEventKey($event);
        if ($missing !== null) {
            throw new ConfigError("check(): event is missing required key '{$missing}'");
        }

        if (array_key_exists('payload', $event) && !is_array($event['payload'])) {
            throw new ConfigError("check(): event 'payload' must be an array");
        }

        $this->metrics->inc('check_total');

        $wireBody = Wire::eventToWire($event);
        $requestId = Uuid::v4();
        $idempotencyKey = Uuid::v4();

        try {
            $response = $this->transport->post(
                Constants::checkUrl(),
                $wireBody,
                new PostOptions($this->checkTimeoutMs, $requestId, $idempotencyKey),
            );
        } catch (ServerError $e) {
            return $this->handleCheckServerError($e);
        } catch (TimeoutError | NetworkError $e) {
            return $this->handleCheckTransientError($e);
        } catch (SignalGateError $e) {
            // Already inside the taxonomy (e.g. a custom transport's own
            // ConfigError) - a single `catch (SignalGateError)` already
            // covers it, so it is left to propagate untouched.
            throw $e;
        } catch (\Throwable $e) {
            // The robustness barrier: a transport can fail in
            // ways no taxonomy clause names (an ordinary bug, or - as in
            // production `CurlTransport::post()` - `Wire::encodeBody()`
            // throwing a bare `JsonException` on an unencodable `custom`/
            // `payload` value). Classifying it as a `NetworkError` and
            // routing it through the existing transient-error branching
            // guarantees it is EITHER absorbed by fail-open OR still a
            // `SignalGateError` - never a raw throwable escaping the gate.
            return $this->handleCheckTransientError(self::classifyAsNetworkError($e));
        }

        try {
            $result = Wire::checkResultFromData($response->body, $this->logger);
        } catch (ServerError $e) {
            return $this->handleCheckServerError($e);
        }

        $this->metrics->inc('check_success_total');

        return $result;
    }

    /**
     * §3.3 — append-only, NEVER throws, NEVER does I/O. Delivery happens
     * later in `flush()`/`close()`/the shutdown hook.
     *
     * @param array<string, mixed> $event
     */
    public function log(array $event): void
    {
        if ($this->closed) {
            return;
        }

        $missing = $this->firstMissingEventKey($event);
        if ($missing !== null) {
            $this->logger->error('signalgate.log.invalid_event', ['missing' => $missing]);

            return;
        }

        if (array_key_exists('payload', $event) && !is_array($event['payload'])) {
            // §3.3 — log() never throws; a non-array `payload` is silently
            // dropped, just like a missing key, but noted for observability.
            $this->logger->error('signalgate.log.invalid_event', ['reason' => "'payload' must be an array"]);

            return;
        }

        $wireBody = Wire::eventToWire($event);
        $idempotencyKey = Uuid::v4();

        $this->ensureLogBuffer()->append($wireBody, $idempotencyKey);
    }

    /** PHP-specific addition — drain the buffer now. Safe on an empty buffer. */
    public function flush(): void
    {
        if ($this->logBuffer === null) {
            return;
        }

        $this->logBuffer->drain(null);
    }

    /**
     * §3.1 — idempotent, drains, releases cURL. Marks the Client closed
     * BEFORE draining, so re-entrant `check()`/`log()` calls during the
     * drain correctly observe "closed".
     */
    public function close(?float $deadlineSeconds = null): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->logBuffer !== null) {
            $this->logBuffer->markClosedAndDrain($deadlineSeconds);
        }

        if ($this->transportOwned) {
            $this->transport->close();
        }
    }

    public function metrics(): Metrics
    {
        return $this->metrics;
    }

    /**
     * BACKEND_SDK_SPEC §3.1, §8.3 — cloning is refused outright.
     * There is no correct shallow copy of an object that owns a connection
     * and a delivery queue: a sibling would silently share the original's
     * transport, `Metrics` and log buffer while keeping its own copy of
     * `$closed`, so `close()` on one leaves the other believing it is open
     * on top of an already-released handle. A non-public `__clone()` makes
     * the engine raise `\Error` on `clone $client` - deliberately not an
     * exception, so a broad `catch (\Exception)` cannot swallow it and carry
     * on with a half-working Client.
     */
    private function __clone()
    {
    }

    /** Lazily constructs the single {@see LogBuffer} this Client owns. */
    private function ensureLogBuffer(): LogBuffer
    {
        if ($this->logBuffer === null) {
            $this->logBuffer = new LogBuffer(
                $this->transport,
                $this->metrics,
                $this->logQueueCapacity,
                $this->logMaxRetries,
                $this->logRetryBaseMs,
                $this->logTimeoutMs,
                $this->logger,
                $this->sleeper,
            );
        }

        return $this->logBuffer;
    }

    /** @param array<string, mixed> $event */
    private function firstMissingEventKey(array $event): ?string
    {
        foreach (self::REQUIRED_EVENT_KEYS as $key) {
            if (!array_key_exists($key, $event)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * A `ServerError` thrown by the transport, or synthesized by
     * `Wire::checkResultFromData()` for a malformed 2xx body (§A5 step 6).
     */
    private function handleCheckServerError(ServerError $e): CheckResult
    {
        $type = $e->code === 'MALFORMED_RESPONSE' ? 'MalformedResponse' : 'ServerError';
        $this->metrics->inc('check_error_total', ['type' => $type]);

        // A REAL 4xx from the transport is a caller/config bug: always
        // rethrown, never swallowed by fail-open (§6.1, §9 row 4).
        if ($type === 'ServerError' && $e->statusCode >= 400 && $e->statusCode < 500) {
            throw $e;
        }

        if (!$this->failOpen) {
            throw $e;
        }

        $this->metrics->inc('check_failed_open_total');
        $this->logger->warn('signalgate.check.failed_open', ['reason' => $type]);

        return Wire::failedOpenResult();
    }

    /** A `TimeoutError` or `NetworkError` thrown by the transport (§6.1). */
    private function handleCheckTransientError(SignalGateError $e): CheckResult
    {
        $type = $e instanceof TimeoutError ? 'TimeoutError' : 'NetworkError';
        $this->metrics->inc('check_error_total', ['type' => $type]);

        if (!$this->failOpen) {
            throw $e;
        }

        $this->metrics->inc('check_failed_open_total');
        $this->logger->warn('signalgate.check.failed_open', ['reason' => $type]);

        return Wire::failedOpenResult();
    }

    /**
     * The robustness barrier (BACKEND_SDK_SPEC §6.1/§6.2): folds
     * any `\Throwable` the taxonomy does not name into a `NetworkError` -
     * the taxonomy's "something below HTTP went wrong" bucket - carrying the
     * original diagnostic forward. The api_key never touches this message,
     * so it cannot leak here (§8.4).
     */
    private static function classifyAsNetworkError(\Throwable $e): NetworkError
    {
        return new NetworkError(
            sprintf('signalgate: unexpected %s from transport: %s', get_class($e), $e->getMessage()),
            0,
            $e,
        );
    }

    /**
     * `api_key` is required and non-empty after trimming (§A2, Brief R4 — no
     * `pk_` prefix check). The rejected value never leaks into the message
     * (§8.4), even in the all-whitespace case.
     *
     * Trimming is validation-only: the value RETAINED and later transmitted
     * is the caller's raw, untrimmed bytes (BACKEND_SDK_SPEC §5.1/§A4 —
     * "the api_key travels RAW", matching the Node and Python ports). A key
     * whose surrounding whitespace is significant to the issuer must still
     * authenticate.
     *
     * @param array<string, mixed> $options
     */
    private static function validateApiKey(array $options): string
    {
        $raw = $options['api_key'] ?? '';
        $trimmedForValidationOnly = is_string($raw) ? trim($raw) : '';

        if ($trimmedForValidationOnly === '') {
            throw new ConfigError('api_key is required and must be a non-empty string');
        }

        /** @var string $raw guaranteed a non-empty-when-trimmed string by the check above */
        return $raw;
    }

    /**
     * Every `*_ms` / `*_capacity` / `*_retries` option must be a genuine PHP
     * `int` (never a numeric string, never a float) and non-negative (§A2).
     * `log_queue_capacity` is additionally required to be strictly positive.
     *
     * @param array<string, mixed> $options
     */
    private static function validateNumericOptions(array $options): void
    {
        foreach (self::NUMERIC_OPTIONS as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }

            $value = $options[$key];
            if (!is_int($value) || $value < 0) {
                throw new ConfigError("{$key} must be a non-negative integer");
            }
        }

        if (array_key_exists('log_queue_capacity', $options) && $options['log_queue_capacity'] <= 0) {
            throw new ConfigError('log_queue_capacity must be a positive integer greater than zero');
        }
    }

    /**
     * Unknown option keys are rejected (PHP hardening): assoc arrays have no
     * compile-time checking, so a typo'd option would otherwise silently
     * fall back to its default. There is no tenant-facing `base_url` option.
     *
     * @param array<string, mixed> $options
     */
    private static function validateUnknownKeys(array $options): void
    {
        foreach (array_keys($options) as $key) {
            if (!in_array($key, self::RECOGNIZED_OPTIONS, true)) {
                throw new ConfigError("unknown configuration option '{$key}'");
            }
        }
    }
}
