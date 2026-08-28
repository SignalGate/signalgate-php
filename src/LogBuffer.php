<?php

declare(strict_types=1);

namespace SignalGate;

use SignalGate\Errors\ServerError;

/**
 * Bounded in-memory log buffer with drop-oldest overflow and the §6.3 retry
 * ladder (BACKEND_SDK_SPEC §3.3, §6.3; PORTING_SPEC §A6, §A7, §A8).
 *
 * This is PHP's one architectural divergence from the Node/Python ports:
 * PHP-FPM has no threads or event loop, so `Client::log()` only ever calls
 * {@see self::append()} — no I/O, ever. Delivery happens when something
 * calls {@see self::drain()} (from `Client::flush()`) or
 * {@see self::markClosedAndDrain()} (from `Client::close()` / the shutdown
 * hook).
 *
 * Conservation invariant that every code path below is designed to keep
 * exact (§A8):
 *
 *   log_enqueued_total === log_sent_total
 *       + log_dropped_total{reason="queue_full"}
 *       + log_dropped_total{reason="closed"}
 *       + log_dropped_total{reason="retry_exhausted"}
 */
final class LogBuffer
{
    /** @var list<array{body: array<string, mixed>, idempotencyKey: string}> */
    private array $buffer = [];

    /** Set once by {@see self::markClosedAndDrain()}; makes `close()` idempotent (AC47). */
    private bool $closed = false;

    /** Re-entrancy guard: `drain()` must never run concurrently or twice over the same record. */
    private bool $draining = false;

    private readonly \Closure $sleeper;

    /** @param callable(int): void $sleeper */
    public function __construct(
        private readonly Transport $transport,
        private readonly Metrics $metrics,
        private readonly int $capacity,
        private readonly int $maxRetries,
        private readonly int $retryBaseMs,
        private readonly int $logTimeoutMs,
        private readonly Logger $logger,
        callable $sleeper,
    ) {
        $this->sleeper = \Closure::fromCallable($sleeper);
    }

    /**
     * Append-only, zero I/O (§3.3). Silent no-op once closed (§A6/AC38).
     * Overflow is drop-oldest (addendum §3.1): the evicted record was itself
     * already counted as enqueued, so `queue_full` is the only counter that
     * moves on eviction.
     *
     * @param array<string, mixed> $body
     */
    public function append(array $body, string $idempotencyKey): void
    {
        if ($this->closed) {
            return;
        }

        if (count($this->buffer) === $this->capacity) {
            array_shift($this->buffer);
            $this->metrics->inc('log_dropped_total', ['reason' => 'queue_full']);
            $this->logger->error('signalgate.log.queue_full', ['capacity' => $this->capacity]);
        }

        $this->buffer[] = ['body' => $body, 'idempotencyKey' => $idempotencyKey];
        $this->metrics->inc('log_enqueued_total');
    }

    /**
     * FIFO delivery with the retry ladder. `$deadlineEpochSeconds` is an
     * ABSOLUTE `microtime(true)`-style timestamp, or `null` for "drain until
     * empty". Guarded against re-entrancy: a concurrent/nested call is a
     * silent no-op.
     */
    public function drain(?float $deadlineEpochSeconds = null): void
    {
        if ($this->draining) {
            return;
        }

        $this->draining = true;

        try {
            while ($this->buffer !== []) {
                if ($deadlineEpochSeconds !== null && microtime(true) >= $deadlineEpochSeconds) {
                    $this->dropRemainingAsClosed();
                    break;
                }

                $record = array_shift($this->buffer);
                $this->deliver($record['body'], $record['idempotencyKey']);
            }
        } finally {
            $this->draining = false;
        }
    }

    public function count(): int
    {
        return count($this->buffer);
    }

    /**
     * Idempotent (§A8/AC47): the first call flips `$closed` and drains with a
     * deadline; every subsequent call is a silent no-op. `$deadlineSeconds`
     * is a RELATIVE budget from now — `0.0` means no time at all, `null`
     * means the generous default of `5 * log_timeout_ms` seconds.
     */
    public function markClosedAndDrain(?float $deadlineSeconds): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $budget = $deadlineSeconds ?? (5 * $this->logTimeoutMs / 1000.0);
        $this->drain(microtime(true) + $budget);
    }

    /**
     * The deadline has passed: every record still buffered is dropped
     * without any transport attempt. This is the only code path that ever
     * produces a `reason: "closed"` drop, since `drain()` is only ever
     * called with a non-null deadline via `markClosedAndDrain()`.
     */
    private function dropRemainingAsClosed(): void
    {
        $remaining = count($this->buffer);
        $this->buffer = [];

        for ($i = 0; $i < $remaining; $i++) {
            $this->metrics->inc('log_dropped_total', ['reason' => 'closed']);
        }
    }

    /**
     * Deliver one record with up to `maxRetries + 1` attempts (§6.3). Every
     * throwable from the transport is caught locally (§A7 robustness) so
     * nothing ever escapes `drain()`.
     *
     * @param array<string, mixed> $body
     */
    private function deliver(array $body, string $idempotencyKey): void
    {
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $requestId = Uuid::v4();

            try {
                $this->transport->post(
                    Constants::logUrl(),
                    $body,
                    new PostOptions($this->logTimeoutMs, $requestId, $idempotencyKey),
                );
                $this->metrics->inc('log_sent_total');

                return;
            } catch (ServerError $e) {
                $this->metrics->inc('log_http_error_total', ['status' => (string) $e->statusCode]);

                if ($e->statusCode >= 400 && $e->statusCode < 500) {
                    // Non-retryable client error: drop immediately, never retry.
                    $this->metrics->inc('log_dropped_total', ['reason' => 'retry_exhausted']);
                    $this->logger->warn('signalgate.log.dropped', [
                        'reason' => 'retry_exhausted',
                        'status' => $e->statusCode,
                    ]);

                    return;
                }

                if ($this->isLastAttempt($attempt)) {
                    $this->metrics->inc('log_dropped_total', ['reason' => 'retry_exhausted']);
                    $this->logger->warn('signalgate.log.dropped', [
                        'reason' => 'retry_exhausted',
                        'status' => $e->statusCode,
                    ]);

                    return;
                }

                ($this->sleeper)($this->retryBaseMs * 2 ** $attempt);
            } catch (\Throwable $e) {
                // TimeoutError, NetworkError, or any other unexpected throwable
                // (§A7): all bucketed as "network" and retried the same way.
                $this->metrics->inc('log_http_error_total', ['status' => 'network']);

                if ($this->isLastAttempt($attempt)) {
                    $this->metrics->inc('log_dropped_total', ['reason' => 'retry_exhausted']);
                    $this->logger->warn('signalgate.log.dropped', [
                        'reason' => 'retry_exhausted',
                        'status' => 'network',
                    ]);

                    return;
                }

                ($this->sleeper)($this->retryBaseMs * 2 ** $attempt);
            }
        }
    }

    private function isLastAttempt(int $attempt): bool
    {
        return $attempt === $this->maxRetries;
    }
}
