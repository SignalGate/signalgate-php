<?php

declare(strict_types=1);

namespace SignalGate\Tests\EdgeCases;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\Errors\NetworkError;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;
use SignalGate\Tests\Support\RecordingSleeper;
use SignalGate\Tests\Support\StaticTransport;

/**
 * Hardening amendment — §8.4 redaction, widened from "the key is not in
 * the MESSAGE" to "the key is not RECOVERABLE FROM THE OBJECT".
 *
 * AC51/AC52 pin the key out of log lines, `getMessage()` and `(string) $e`,
 * and those hold. But `Client` and `CurlTransport` each keep the raw key in a
 * plain string property, and `private` is no defence against the dump
 * helpers: `print_r()`, `var_dump()`, `var_export()` and `serialize()` all
 * have full property visibility.
 *
 * The exposure paths are ordinary, not exotic:
 *
 *   - AC60 — `Exception::getTrace()` captures live OBJECT references for every
 *     argument still on the stack. Any helper that takes the Client as a
 *     parameter (`verifyLogin($client, $event)` — DI helpers, queued jobs, test
 *     harnesses) leaves `$client` sitting in `$e->getTrace()[n]['args']`, and
 *     error-monitoring integrations routinely dump the trace with `print_r`/
 *     `var_dump` rather than `getTraceAsString()`.
 *   - AC61 — a Client assembled from serializable seams (what a DI container
 *     produces) put into a queued job payload or a session.
 *   - AC62 — a framework debug page dumping its container's services, which is
 *     the only path that reaches the key `CurlTransport` holds.
 *
 * All three are asserted WITHOUT reflection on private state and WITHOUT any
 * network call: they use only what a caller can already do to these objects.
 * The dumps are searched with `str_contains()` rather than
 * `assertStringNotContainsString()` on purpose: a leaking dump is a hundred
 * kilobytes of hex (PHP's own `Metrics` keys embed NUL bytes), and a red must
 * print the broken contract, not the haystack.
 *
 * Covers AC60, AC61, AC62.
 */
final class ApiKeyTraceLeakTest extends TestCase
{
    /** A short key and a long one: no length-dependent masking may squeak through. */
    private const SHORT_KEY = 'pk_7f3a';

    private const LONG_KEY = 'super-secret-pk-value-xyz-9f8e7d6c-0123456789abcdef';

    /**
     * AC60 (§8.4) — the raw key must not survive a dumped exception trace, at
     * either key length.
     *
     * NOTE: the two key lengths are looped INSIDE the test rather than fed by
     * a `DataProvider`. A provider hands the key to the test method as an
     * ARGUMENT, and PHPUnit's own invocation frames are part of the very trace
     * under assertion — the raw key would then appear in the dump no matter
     * what the SDK does, an unfixable false red.
     */
    public function testAc60ApiKeyNeverSurvivesADumpedExceptionTrace(): void
    {
        foreach ([self::SHORT_KEY, self::LONG_KEY] as $apiKey) {
            $fake = new FakeTransport(static function (RecordedRequest $r): never {
                throw new NetworkError('connection refused');
            });
            $client = new Client(Fixtures::options([
                'api_key' => $apiKey,
                'transport' => $fake,
                'fail_open' => false,
            ]));

            try {
                // The idiomatic integration shape: a helper that takes the
                // live Client as a parameter.
                self::verifyLogin($client, Fixtures::sampleEvent());
                self::fail('expected the NetworkError to propagate with fail_open = false');
            } catch (NetworkError $e) {
                $trace = $e->getTrace();

                self::assertTrue(
                    self::traceCarriesTheClient($trace),
                    'teeth: the trace must actually carry the Client for this contract to mean anything'
                    . ' (requires zend.exception_ignore_args = 0)',
                );

                // AC52's own two checks — these already hold, and must keep holding.
                self::assertStringNotContainsString($apiKey, $e->getMessage());
                self::assertStringNotContainsString($apiKey, (string) $e);

                ob_start();
                var_dump($trace);
                $varDumped = (string) ob_get_clean();

                self::assertFalse(
                    str_contains($varDumped, $apiKey),
                    "var_dump(\$e->getTrace()) exposed the raw api_key ({$apiKey})",
                );
                self::assertFalse(
                    str_contains(print_r($trace, true), $apiKey),
                    "print_r(\$e->getTrace(), true) exposed the raw api_key ({$apiKey})",
                );
                self::assertFalse(
                    str_contains(var_export($trace, true), $apiKey),
                    "var_export(\$e->getTrace(), true) exposed the raw api_key ({$apiKey})",
                );
            }
        }
    }

    /**
     * AC61 (§8.4) — the raw key must not survive `serialize()`, the form a
     * Client takes when it rides along in a queued job payload or a session.
     *
     * The log buffer is deliberately never instantiated here: it holds a
     * `Closure` sleeper, and PHP refuses to serialize closures for reasons
     * that have nothing to do with the key. Every other seam is injected as a
     * plain object — the shape a DI container produces.
     *
     * Refusing to serialize a Client AT ALL is an equally acceptable answer to
     * this criterion (the same argument as the AC65 clone guard), so both
     * outcomes are allowed — what is forbidden is bytes that carry the key.
     */
    #[DataProvider('provideApiKeys')]
    public function testAc61ApiKeyNeverSurvivesSerialization(string $apiKey): void
    {
        $client = new Client([
            'api_key' => $apiKey,
            'register_shutdown' => false,
            'transport' => new StaticTransport(),
            'sleeper' => new RecordingSleeper(),
        ]);
        $client->check(Fixtures::sampleEvent());

        try {
            $serialized = serialize($client);
        } catch (\Throwable $refusal) {
            self::assertFalse(
                str_contains((string) $refusal, $apiKey),
                "the refusal to serialize exposed the raw api_key ({$apiKey})",
            );

            return;
        }

        self::assertTrue(
            str_contains($serialized, 'SignalGate\\Client'),
            'teeth: the Client itself must really be in the serialized bytes',
        );
        self::assertFalse(
            str_contains($serialized, $apiKey),
            "serialize(\$client) exposed the raw api_key ({$apiKey})",
        );
    }

    /**
     * AC62 (§8.4) — the raw key must not survive a dump of a
     * production-shaped Client: no transport injected, so the Client owns a
     * real `CurlTransport`, which is the object that actually needs the key.
     * Constructing the cURL handle is inert — nothing is ever posted, so this
     * stays a no-network test.
     */
    #[DataProvider('provideApiKeys')]
    public function testAc62ApiKeyNeverSurvivesADumpOfAProductionShapedClient(string $apiKey): void
    {
        $client = new Client(Fixtures::options(['api_key' => $apiKey]));
        // Left unclosed on purpose: close() would call curl_close(), which is
        // deprecated as of PHP 8.5 and would add a diagnostic unrelated to the
        // behaviour under assertion. The handle is released with the object.

        $printed = print_r($client, true);

        ob_start();
        var_dump($client);
        $varDumped = (string) ob_get_clean();

        $exported = var_export($client, true);

        self::assertTrue(
            str_contains($printed, 'SignalGate\\Client'),
            'teeth: the real Client (and the CurlTransport it owns) must really be dumped',
        );
        self::assertFalse(str_contains($printed, $apiKey), "print_r(\$client, true) exposed the raw api_key ({$apiKey})");
        self::assertFalse(str_contains($varDumped, $apiKey), "var_dump(\$client) exposed the raw api_key ({$apiKey})");
        self::assertFalse(str_contains($exported, $apiKey), "var_export(\$client, true) exposed the raw api_key ({$apiKey})");
    }

    /** @return iterable<string, array{string}> */
    public static function provideApiKeys(): iterable
    {
        yield 'short key' => [self::SHORT_KEY];
        yield 'long key' => [self::LONG_KEY];
    }

    /**
     * The idiomatic wrapper: takes the live Client as an argument, as a real
     * integration would.
     *
     * @param array<string, mixed> $event
     */
    private static function verifyLogin(Client $client, array $event): void
    {
        $client->check($event);
    }

    /** @param list<array<string, mixed>> $trace */
    private static function traceCarriesTheClient(array $trace): bool
    {
        foreach ($trace as $frame) {
            foreach ((array) ($frame['args'] ?? []) as $arg) {
                if ($arg instanceof Client) {
                    return true;
                }
            }
        }

        return false;
    }
}
