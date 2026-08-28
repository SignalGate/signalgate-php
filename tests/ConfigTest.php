<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\Errors\ConfigError;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;

/**
 * Group B — constructor validation (BACKEND_SDK_SPEC §3.1; PORTING_SPEC §A2).
 *
 * Covers AC5, AC6, AC7, AC8, AC9, AC10.
 */
final class ConfigTest extends TestCase
{
    /** AC5 — `api_key` must be a non-empty string (§A2). */
    public function testAc5MissingOrBlankApiKeyThrowsConfigError(): void
    {
        foreach ([[], ['api_key' => ''], ['api_key' => '   ']] as $index => $bad) {
            $message = $this->captureConfigErrorMessage(
                array_merge($bad, ['register_shutdown' => false]),
            );

            self::assertStringContainsString(
                'api_key',
                $message,
                "case #{$index}: the ConfigError must name the offending option",
            );
        }

        // The rejected value must never leak into the message (§8.4).
        $whitespaceCase = $this->captureConfigErrorMessage(
            ['api_key' => '   ', 'register_shutdown' => false],
        );
        self::assertStringNotContainsString('   ', $whitespaceCase);
    }

    /**
     * AC6 — every `*_ms` / `*_capacity` / `*_retries` option must be a
     * NON-NEGATIVE INTEGER (§A2): a negative int, a numeric string and a float
     * are all rejected. 5 options x 3 bad values = 15 cases.
     */
    #[DataProvider('provideInvalidNumericOptions')]
    public function testAc6NonNegativeIntegerOptionsAreValidated(string $option, mixed $value): void
    {
        $message = $this->captureConfigErrorMessage([
            'api_key' => 'jwt-test',
            'register_shutdown' => false,
            $option => $value,
        ]);

        self::assertStringContainsString(
            $option,
            $message,
            'the ConfigError must name the offending option',
        );
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function provideInvalidNumericOptions(): iterable
    {
        $options = [
            'check_timeout_ms',
            'log_timeout_ms',
            'log_queue_capacity',
            'log_max_retries',
            'log_retry_base_ms',
        ];
        $values = ['negative int' => -1, 'numeric string' => '3000', 'float' => 3000.5];

        foreach ($options as $option) {
            foreach ($values as $label => $value) {
                yield "{$option} / {$label}" => [$option, $value];
            }
        }
    }

    /**
     * AC7 — `log_queue_capacity` must be strictly POSITIVE (§A2). `0` passes
     * the non-negative-integer rule of AC6, so this is a distinct rule.
     */
    public function testAc7ZeroQueueCapacityThrowsConfigError(): void
    {
        $message = $this->captureConfigErrorMessage([
            'api_key' => 'jwt-test',
            'register_shutdown' => false,
            'log_queue_capacity' => 0,
        ]);

        self::assertStringContainsString('log_queue_capacity', $message);
        self::assertStringContainsString('positive', $message);
    }

    /**
     * AC8 — unknown option keys are rejected (PHP hardening): assoc arrays have
     * no compile-time checking, so a typo would otherwise silently take the
     * default. `base_url` in particular must NOT be a tenant-facing option
     * (§2 forbids tenant base-URL overrides).
     */
    public function testAc8UnknownOptionKeyThrowsConfigError(): void
    {
        $message = $this->captureConfigErrorMessage([
            'api_key' => 'pk_test',
            'register_shutdown' => false,
            'base_url' => 'http://evil',
        ]);

        self::assertStringContainsString('base_url', $message);
    }

    /** AC9 — the §A2 defaults are APPLIED to real requests, not merely stored. */
    public function testAc9DefaultTimeoutsReachTheTransport(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $req) => str_ends_with($req->url, '/check')
            ? Fixtures::checkSuccessResponse($req)
            : Fixtures::logSuccessResponse($req));

        $client = new Client(Fixtures::options(['api_key' => 'pk_test', 'transport' => $fake]));

        $client->check(Fixtures::sampleEvent());
        self::assertSame(3000, $fake->requests[0]->timeoutMs);

        $client->log(Fixtures::sampleEvent());
        $client->flush();
        self::assertSame(1000, $fake->requests[1]->timeoutMs);
    }

    /**
     * AC10 (Brief R4) — the api key is validated NON-EMPTY ONLY. There is no
     * `pk_` prefix check: the family's shared fixtures use `jwt-test`, and a
     * prefix rule would diverge from both shipped ports.
     */
    public function testAc10NonPkPrefixedApiKeyIsAccepted(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $req) => Fixtures::checkSuccessResponse($req));

        $client = new Client(Fixtures::options(['api_key' => 'jwt-test', 'transport' => $fake]));
        $result = $client->check(Fixtures::sampleEvent());

        self::assertSame('allow', $result->action);
        self::assertFalse($result->failedOpen);
        self::assertSame(1, $fake->count());
    }

    /** @param array<string, mixed> $options */
    private function captureConfigErrorMessage(array $options): string
    {
        try {
            new Client($options);
        } catch (ConfigError $e) {
            return $e->getMessage();
        }

        self::fail('expected a SignalGate\Errors\ConfigError, none was thrown');
    }
}
