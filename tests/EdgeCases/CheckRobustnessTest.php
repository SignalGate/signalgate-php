<?php

declare(strict_types=1);

namespace SignalGate\Tests\EdgeCases;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalGate\CheckResult;
use SignalGate\Client;
use SignalGate\Errors\NetworkError;
use SignalGate\Errors\SignalGateError;
use SignalGate\HttpResponse;
use SignalGate\PostOptions;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Transport;
use SignalGate\Wire;

/**
 * Hardening amendment — `check()` is TOTAL (§3.2, §6.1, §6.2).
 *
 * Two promises meet here and neither is currently kept:
 *
 *   1. fail-open (§6.1) — an infrastructure failure must never take the
 *      caller's login flow down with it; and
 *   2. the taxonomy (§6.2, §A10) — "every error the SDK can throw is a
 *      `SignalGateError`, so `catch (SignalGateError)` catches all of them
 *      with a single clause".
 *
 * `Client::check()` catches `ServerError`, `TimeoutError` and `NetworkError`
 * BY NAME, so any other `Throwable` raised by a transport escapes raw: it
 * neither fails open nor lands inside the taxonomy, and it is counted by no
 * metric. The frozen 85 cannot see this because every one of them uses a
 * `FakeTransport` that hands back an already-decoded array — the real
 * `CurlTransport::post()` additionally runs `Wire::encodeBody()`, which throws
 * a bare `JsonException` on a caller's unencodable `custom`/`payload` value.
 *
 * Covers AC57, AC58, AC59.
 */
final class CheckRobustnessTest extends TestCase
{
    private const SECRET = 'super-secret-pk-value-xyz-9f8e7d6c';

    /**
     * AC57 (§6.1, §9 row 2) — under the DEFAULT posture, a transport failure
     * that fits no taxonomy class still fails open: a synthesized `allow`,
     * counted as a `NetworkError` (the taxonomy's "something below HTTP went
     * wrong" bucket), never an escaping raw throwable.
     */
    #[DataProvider('provideNonTaxonomyFailures')]
    public function testAc57NonTaxonomyTransportFailureFailsOpen(string $class, string $message): void
    {
        $client = new Client(Fixtures::options([
            'api_key' => self::SECRET,
            'transport' => self::throwingTransport(new $class($message)),
        ]));

        try {
            $result = $client->check(Fixtures::sampleEvent());
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            self::fail(sprintf(
                'check() must fail open on an unexpected %s, not let it escape uncaught ("%s")',
                get_class($e),
                $e->getMessage(),
            ));
        }

        self::assertTrue($result->failedOpen, "an unexpected {$class} must fail OPEN, not crash the caller");
        self::assertSame('allow', $result->action);
        self::assertSame(0.0, $result->score);
        self::assertSame(1, $client->metrics()->get('check_error_total', ['type' => 'NetworkError']));
        self::assertSame(1, $client->metrics()->get('check_failed_open_total'));
        self::assertSame(0, $client->metrics()->get('check_success_total'));
    }

    /**
     * AC58 (§6.2, §A10) — with `fail_open = false` the same failure surfaces
     * as a `NetworkError`: inside the taxonomy, carrying the original
     * diagnostic forward, and carrying no api key (§8.4).
     */
    #[DataProvider('provideNonTaxonomyFailures')]
    public function testAc58NonTaxonomyTransportFailureSurfacesInsideTheTaxonomy(string $class, string $message): void
    {
        $client = new Client(Fixtures::options([
            'api_key' => self::SECRET,
            'transport' => self::throwingTransport(new $class($message)),
            'fail_open' => false,
        ]));

        try {
            $client->check(Fixtures::sampleEvent());
            self::fail("expected {$class} to surface as a SignalGate taxonomy error");
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            self::assertInstanceOf(
                NetworkError::class,
                $e,
                sprintf('check() let a raw %s escape instead of classifying it', get_class($e)),
            );
            self::assertStringContainsString(
                $message,
                $e->getMessage(),
                'the original diagnostic must survive classification',
            );
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, (string) $e);
        }

        self::assertSame(1, $client->metrics()->get('check_error_total', ['type' => 'NetworkError']));
        self::assertSame(0, $client->metrics()->get('check_failed_open_total'));
    }

    /**
     * Failures no taxonomy clause names: an ordinary `Exception` subclass and
     * an `Error` subclass, because `catch (\Exception)` is only half of
     * `catch (\Throwable)`.
     *
     * @return iterable<string, array{class-string<\Throwable>, string}>
     */
    public static function provideNonTaxonomyFailures(): iterable
    {
        yield 'RuntimeException' => [\RuntimeException::class, 'boom-transport-detail'];
        yield 'TypeError' => [\TypeError::class, 'type-fixture-detail'];
    }

    /**
     * AC59 (§3.2, §6.2) — the realistic trigger: a caller's `custom` value
     * that JSON cannot represent. The encoder runs INSIDE the transport (as
     * it does in `CurlTransport::post()`), so `check()` must absorb the
     * failure — either by delivering the request or by failing open — and
     * must never hand the caller a bare `JsonException`. With
     * `fail_open = false` whatever is raised must still be a
     * `SignalGateError`, so one `catch` clause remains sufficient.
     */
    public function testAc59UnencodableCustomNeverCrashesTheGate(): void
    {
        $event = Fixtures::sampleEvent();
        $event['custom'] = ['bad' => "\xB1\x31"]; // invalid UTF-8: json_encode cannot represent it

        $client = new Client(Fixtures::options([
            'api_key' => self::SECRET,
            'transport' => self::encodingTransport(),
        ]));

        try {
            $result = $client->check($event);
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            self::fail(sprintf(
                'check() must absorb an unencodable custom value, not hand the caller a raw %s ("%s")',
                get_class($e),
                $e->getMessage(),
            ));
        }

        self::assertInstanceOf(CheckResult::class, $result);
        self::assertSame('allow', $result->action, 'the gate must still return a verdict, delivered or failed open');

        $strict = new Client(Fixtures::options([
            'api_key' => self::SECRET,
            'transport' => self::encodingTransport(),
            'fail_open' => false,
        ]));

        try {
            $strict->check($event);
        } catch (\Throwable $e) {
            self::assertInstanceOf(
                SignalGateError::class,
                $e,
                sprintf('catch (SignalGateError) must catch everything; %s escapes it', get_class($e)),
            );
            self::assertStringNotContainsString(self::SECRET, (string) $e);
        }
    }

    /** A transport that fails the way no taxonomy clause names. */
    private static function throwingTransport(\Throwable $error): Transport
    {
        return new class($error) implements Transport {
            public function __construct(private readonly \Throwable $error)
            {
            }

            public function post(string $url, array $body, PostOptions $options): HttpResponse
            {
                throw $this->error;
            }

            public function close(): void
            {
            }
        };
    }

    /**
     * A transport that encodes the body before answering — the one thing the
     * production `CurlTransport` does that `FakeTransport` does not.
     */
    private static function encodingTransport(): Transport
    {
        return new class implements Transport {
            public function post(string $url, array $body, PostOptions $options): HttpResponse
            {
                Wire::encodeBody($body);

                return new HttpResponse(
                    200,
                    ['data' => ['action' => 'allow', 'score' => 0.0]],
                    $options->requestId,
                );
            }

            public function close(): void
            {
            }
        };
    }
}
