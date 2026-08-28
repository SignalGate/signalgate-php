<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\Errors\ConfigError;
use SignalGate\Errors\NetworkError;
use SignalGate\Errors\ServerError;
use SignalGate\Errors\SignalGateError;
use SignalGate\Errors\TimeoutError;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;

/**
 * Group F — error taxonomy (§6.2, §A10).
 *
 * Covers AC31, AC32, AC33, AC34.
 */
final class ErrorsTest extends TestCase
{
    /**
     * AC31 (§A10) — one rooted hierarchy: a single `catch (SignalGateError)`
     * catches all four subtypes; `catch (ServerError)` catches only its own.
     */
    public function testAc31ErrorHierarchyNarrowsBothWays(): void
    {
        $errors = [
            'ConfigError' => new ConfigError('bad config'),
            'TimeoutError' => new TimeoutError('too slow'),
            'NetworkError' => new NetworkError('dns'),
            'ServerError' => new ServerError(500, 'BOOM', 'kaboom', 'req_1'),
        ];

        foreach ($errors as $label => $error) {
            self::assertInstanceOf(SignalGateError::class, $error, "{$label} must be a SignalGateError");
            self::assertInstanceOf(\RuntimeException::class, $error, "{$label} must be a RuntimeException");
            self::assertInstanceOf(\Throwable::class, $error, "{$label} must be a Throwable");

            $caught = null;
            try {
                throw $error;
            } catch (SignalGateError $e) {
                $caught = $e;
            }
            self::assertSame($error, $caught, "catch (SignalGateError) must catch {$label}");
        }

        // Narrowing the other way: only ServerError is a ServerError.
        self::assertInstanceOf(ServerError::class, $errors['ServerError']);
        self::assertNotInstanceOf(ServerError::class, $errors['ConfigError']);
        self::assertNotInstanceOf(ServerError::class, $errors['TimeoutError']);
        self::assertNotInstanceOf(ServerError::class, $errors['NetworkError']);

        // ...and the four subtypes are siblings, not ancestors of each other.
        self::assertNotInstanceOf(ConfigError::class, $errors['TimeoutError']);
        self::assertNotInstanceOf(TimeoutError::class, $errors['NetworkError']);
    }

    /**
     * AC32 (§6.2, §A10) — `ServerError` carries the whole error envelope and
     * renders `"[<statusCode>] <code>: <message>"`.
     *
     * PHP's `Exception::getMessage()` is final, so the raw server text is
     * additionally exposed as `$serverMessage`.
     */
    public function testAc32ServerErrorCarriesTheEnvelope(): void
    {
        $error = new ServerError(422, 'INVALID_PAYLOAD', 'nonce reused', 'req_x', ['field' => 'nonce']);

        self::assertSame(422, $error->statusCode);
        self::assertSame('INVALID_PAYLOAD', $error->code);
        self::assertSame('nonce reused', $error->serverMessage);
        self::assertSame('req_x', $error->requestId);
        self::assertSame(['field' => 'nonce'], $error->details);
        self::assertSame('[422] INVALID_PAYLOAD: nonce reused', $error->getMessage());

        // `details` omitted => null, never an empty array (no `[]`-vs-`null` ambiguity).
        $withoutDetails = new ServerError(500, 'BOOM', 'kaboom', 'req_y');
        self::assertNull($withoutDetails->details);
    }

    /**
     * AC33 (Brief R5) — `QueueFullError` was explicitly REMOVED from the
     * tracked spec (§6.2: "There is no queue-full error type"). It must not be
     * resurrected: overflow is a counter, not an exception.
     */
    public function testAc33QueueFullErrorDoesNotExist(): void
    {
        self::assertFalse(class_exists('SignalGate\Errors\QueueFullError'));
        self::assertFalse(interface_exists('SignalGate\Errors\QueueFullError'));
    }

    /**
     * AC34 (gap resolution) — a malformed event handed to `check()`
     * raises `ConfigError` naming the missing key, and leaks neither the api
     * key nor any payload bytes (§8.4).
     */
    public function testAc34MalformedEventRaisesConfigErrorNamingTheMissingKey(): void
    {
        $secretKey = 'super-secret-pk-value-xyz-9f8e7d6c';
        $secretBlob = 'SECRET-ENCRYPTED-BLOB-DO-NOT-LEAK';

        // First missing key wins: §4.1 order is user_id, ip, method, timestamp, payload.
        $message = $this->captureCheckConfigError($secretKey, ['user_id' => 'u']);
        self::assertMatchesRegularExpression('/\bip\b/', $message);
        self::assertStringNotContainsString($secretKey, $message);

        // A missing `payload` is named too.
        $noPayload = Fixtures::sampleEvent();
        unset($noPayload['payload']);
        $message = $this->captureCheckConfigError($secretKey, $noPayload);
        self::assertStringContainsString('payload', $message);
        self::assertStringNotContainsString($secretKey, $message);

        // Payload bytes are never echoed back into the message.
        $noMethod = Fixtures::sampleEvent([
            'payload' => Fixtures::samplePayload(['encrypted' => $secretBlob]),
        ]);
        unset($noMethod['method']);
        $message = $this->captureCheckConfigError($secretKey, $noMethod);
        self::assertStringContainsString('method', $message);
        self::assertStringNotContainsString($secretBlob, $message);
        self::assertStringNotContainsString($secretKey, $message);
    }

    /** @param array<string, mixed> $event */
    private function captureCheckConfigError(string $apiKey, array $event): string
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::checkSuccessResponse($r));
        $client = new Client(Fixtures::options(['api_key' => $apiKey, 'transport' => $fake]));

        try {
            $client->check($event);
        } catch (ConfigError $e) {
            self::assertSame(0, $fake->count(), 'a malformed event must never reach the transport');

            return $e->getMessage();
        }

        self::fail('expected a ConfigError for a malformed event');
    }
}
