<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalGate\CheckResult;
use SignalGate\Client;
use SignalGate\Errors\ConfigError;
use SignalGate\Errors\NetworkError;
use SignalGate\Errors\ServerError;
use SignalGate\Errors\TimeoutError;
use SignalGate\HttpResponse;
use SignalGate\Tests\Support\CapturingLogger;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;

/**
 * Groups D + E — `check()` response parsing (§4.2, §A5) and resilience
 * (§6.1, §6.2, §6.3).
 *
 * Covers AC18, AC19, AC20, AC21, AC22, AC23, AC24, AC25, AC26, AC27, AC28,
 * AC29, AC30.
 */
final class CheckTest extends TestCase
{
    /** AC18 (§9 row 1) — the §Appendix golden verdict parses field-for-field. */
    public function testAc18HappyPathParsesGoldenEnvelope(): void
    {
        $fake = new FakeTransport(
            static fn (RecordedRequest $r) => new HttpResponse(200, Fixtures::goldenCheckEnvelope(), 'req_1'),
        );
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $result = $client->check(Fixtures::sampleEvent());

        self::assertInstanceOf(CheckResult::class, $result);
        self::assertSame('allow', $result->action);
        self::assertSame(0.0, $result->score);
        self::assertSame('req_1', $result->requestId);
        self::assertSame('acme', $result->tenantId);
        self::assertSame('2026-04-01T13:08:50Z', $result->timestamp);
        self::assertSame(812, $result->processingTimeUs);
        self::assertFalse($result->failedOpen);

        self::assertSame(1, $client->metrics()->get('check_total'));
        self::assertSame(1, $client->metrics()->get('check_success_total'));
    }

    /** AC19 (§A5 `from_data`) — missing fields DEFAULT, they do not throw. */
    public function testAc19EmptyDataObjectDefaultsEveryField(): void
    {
        $fake = new FakeTransport(
            static fn (RecordedRequest $r) => new HttpResponse(200, ['data' => []], $r->requestId),
        );
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $result = $client->check(Fixtures::sampleEvent());

        self::assertSame('', $result->action);
        self::assertSame(0.0, $result->score);
        self::assertSame('', $result->requestId);
        self::assertSame('', $result->tenantId);
        self::assertSame('', $result->timestamp);
        self::assertSame(0, $result->processingTimeUs);
        self::assertFalse($result->failedOpen);
    }

    /**
     * AC20 (§A5, forward-compat) — an action outside the known set is returned
     * VERBATIM (still a success) with a `warn` naming it.
     */
    public function testAc20UnknownActionIsForwardedVerbatimWithWarning(): void
    {
        $logger = new CapturingLogger();
        $fake = new FakeTransport(static fn (RecordedRequest $r) => new HttpResponse(
            200,
            ['data' => [
                'action' => 'quarantine',
                'score' => 0.75,
                'request_id' => $r->requestId,
                'tenant_id' => 'acme',
                'timestamp' => '2026-04-01T13:08:50Z',
                'processing_time_us' => 5,
            ]],
            $r->requestId,
        ));
        $client = new Client(Fixtures::options(['transport' => $fake, 'logger' => $logger]));

        $result = $client->check(Fixtures::sampleEvent());

        self::assertSame('quarantine', $result->action);
        self::assertSame(1, $client->metrics()->get('check_success_total'));

        $warnings = implode("\n", $logger->messagesAtLevel('warn'));
        self::assertStringContainsString('unknown_action', $warnings);
    }

    /**
     * AC21 (§A5 step 6) — a 2xx whose `data` is missing or not an object is
     * MALFORMED: fail open (default posture), one transport call, no success.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('provideMalformedBodies')]
    public function testAc21MalformedResponseFailsOpen(array $body): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => new HttpResponse(200, $body, $r->requestId));
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $result = $client->check(Fixtures::sampleEvent());

        self::assertTrue($result->failedOpen);
        self::assertSame('allow', $result->action);
        self::assertSame(1, $client->metrics()->get('check_error_total', ['type' => 'MalformedResponse']));
        self::assertSame(1, $client->metrics()->get('check_failed_open_total'));
        self::assertSame(0, $client->metrics()->get('check_success_total'));
        self::assertSame(1, $fake->count());
    }

    /**
     * AC22 (§A5 step 6) — the same two bodies with `fail_open = false` raise a
     * `ServerError(code = MALFORMED_RESPONSE)` instead.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('provideMalformedBodies')]
    public function testAc22MalformedResponseRaisesWhenFailOpenIsOff(array $body): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => new HttpResponse(200, $body, $r->requestId));
        $client = new Client(Fixtures::options(['transport' => $fake, 'fail_open' => false]));

        try {
            $client->check(Fixtures::sampleEvent());
            self::fail('expected a ServerError for a malformed 2xx body');
        } catch (ServerError $e) {
            self::assertSame('MALFORMED_RESPONSE', $e->code);
        }

        self::assertSame(0, $client->metrics()->get('check_failed_open_total'));
        self::assertSame(0, $client->metrics()->get('check_success_total'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideMalformedBodies(): iterable
    {
        yield 'no data key' => [['ok' => true]];
        yield 'data is not an object' => [['ok' => true, 'data' => 'not-an-object']];
    }

    /** AC23 (§4.2) — the action -> score table, parsed verbatim from the envelope. */
    #[DataProvider('provideActionScores')]
    public function testAc23ActionScoreTable(string $action, float $score): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => new HttpResponse(
            200,
            ['data' => [
                'action' => $action,
                'score' => $score,
                'request_id' => 'req_score',
                'tenant_id' => 'acme',
                'timestamp' => '2026-04-01T13:08:50Z',
                'processing_time_us' => 7,
            ]],
            $r->requestId,
        ));
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $result = $client->check(Fixtures::sampleEvent());

        self::assertSame($action, $result->action);
        self::assertSame($score, $result->score);
        self::assertFalse($result->failedOpen);
    }

    /** @return iterable<string, array{string, float}> */
    public static function provideActionScores(): iterable
    {
        yield 'block' => ['block', 1.0];
        yield 'dry_run_block' => ['dry_run_block', 0.5];
        yield 'admin_alert' => ['admin_alert', 0.25];
        yield 'allow' => ['allow', 0.0];
    }

    /** AC24 (§6.1, §9 row 2) — a timeout fails open to the synthesized allow. */
    public function testAc24TimeoutFailsOpen(): void
    {
        $logger = new CapturingLogger();
        $fake = new FakeTransport(static function (RecordedRequest $r): never {
            throw new TimeoutError('simulated timeout');
        });
        $client = new Client(Fixtures::options(['transport' => $fake, 'logger' => $logger]));

        $result = $client->check(Fixtures::sampleEvent());

        self::assertTrue($result->failedOpen);
        self::assertSame('allow', $result->action);
        self::assertSame(0.0, $result->score);
        self::assertSame('', $result->requestId);
        self::assertSame('', $result->tenantId);
        self::assertSame('', $result->timestamp);
        self::assertSame(0, $result->processingTimeUs);

        self::assertSame(1, $client->metrics()->get('check_error_total', ['type' => 'TimeoutError']));
        self::assertSame(1, $client->metrics()->get('check_failed_open_total'));
        self::assertNotSame([], $logger->atLevel('warn'), 'the fail-open path must emit a warn (§A5)');
    }

    /** AC25 (§6.1, §9 row 3) — a 5xx fails open. */
    public function testAc25ServerErrorFiveHundredFailsOpen(): void
    {
        $fake = new FakeTransport(static function (RecordedRequest $r): never {
            throw new ServerError(502, 'BAD_GATEWAY', 'upstream is down', $r->requestId);
        });
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $result = $client->check(Fixtures::sampleEvent());

        self::assertTrue($result->failedOpen);
        self::assertSame('allow', $result->action);
        self::assertSame(1, $client->metrics()->get('check_error_total', ['type' => 'ServerError']));
        self::assertSame(1, $client->metrics()->get('check_failed_open_total'));
    }

    /** AC26 (§6.1) — a network error fails open. */
    public function testAc26NetworkErrorFailsOpen(): void
    {
        $fake = new FakeTransport(static function (RecordedRequest $r): never {
            throw new NetworkError('dns failure');
        });
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $result = $client->check(Fixtures::sampleEvent());

        self::assertTrue($result->failedOpen);
        self::assertSame(1, $client->metrics()->get('check_error_total', ['type' => 'NetworkError']));
    }

    /**
     * AC27 (§6.1, §9 row 4) — 4xx PROPAGATES. A 4xx is a caller/config bug and
     * must surface loudly, never be swallowed by fail-open.
     */
    #[DataProvider('provideClientErrors')]
    public function testAc27ClientErrorsPropagate(
        int $status,
        string $code,
        string $message,
        string $requestId,
    ): void {
        $fake = new FakeTransport(static function (RecordedRequest $r) use ($status, $code, $message, $requestId): never {
            throw new ServerError($status, $code, $message, $requestId);
        });
        $client = new Client(Fixtures::options(['transport' => $fake]));

        try {
            $client->check(Fixtures::sampleEvent());
            self::fail("expected a ServerError to propagate for HTTP {$status}");
        } catch (ServerError $e) {
            self::assertSame($status, $e->statusCode);
            self::assertSame($code, $e->code);
            self::assertSame($requestId, $e->requestId);
            self::assertSame("[{$status}] {$code}: {$message}", $e->getMessage());
        }

        self::assertSame(1, $client->metrics()->get('check_error_total', ['type' => 'ServerError']));
        self::assertSame(0, $client->metrics()->get('check_failed_open_total'));
    }

    /** @return iterable<string, array{int, string, string, string}> */
    public static function provideClientErrors(): iterable
    {
        yield '401' => [401, 'UNAUTHORIZED', 'bad key', 'req_9'];
        yield '400' => [400, 'BAD_REQUEST', 'malformed event', 'req_400'];
        yield '403' => [403, 'FORBIDDEN', 'key revoked', 'req_403'];
        yield '404' => [404, 'NOT_FOUND', 'no such route', 'req_404'];
        yield '422' => [422, 'INVALID_PAYLOAD', 'nonce reused', 'req_422'];
    }

    /** AC28 (§6.1, §A14 row 5) — `fail_open = false` propagates the timeout. */
    public function testAc28FailOpenFalsePropagatesTimeout(): void
    {
        $fake = new FakeTransport(static function (RecordedRequest $r): never {
            throw new TimeoutError('simulated timeout');
        });
        $client = new Client(Fixtures::options(['transport' => $fake, 'fail_open' => false]));

        try {
            $client->check(Fixtures::sampleEvent());
            self::fail('expected the TimeoutError to propagate with fail_open = false');
        } catch (TimeoutError $e) {
            self::assertInstanceOf(TimeoutError::class, $e);
        }

        self::assertSame(0, $client->metrics()->get('check_failed_open_total'));
        self::assertSame(1, $client->metrics()->get('check_error_total', ['type' => 'TimeoutError']));
    }

    /**
     * AC29 (§6.3, §A14 row 6) — `check()` has ZERO retries. It is on the
     * latency-sensitive hot path; exactly one transport call, always.
     */
    public function testAc29CheckNeverRetries(): void
    {
        $scenarios = [
            '5xx, fail open' => [static fn (RecordedRequest $r) => throw new ServerError(503, 'UNAVAILABLE', 'down', $r->requestId), true],
            'timeout, fail open' => [static fn (RecordedRequest $r) => throw new TimeoutError('slow'), true],
            '5xx, fail open off' => [static fn (RecordedRequest $r) => throw new ServerError(503, 'UNAVAILABLE', 'down', $r->requestId), false],
            'timeout, fail open off' => [static fn (RecordedRequest $r) => throw new TimeoutError('slow'), false],
        ];

        foreach ($scenarios as $label => [$handler, $failOpen]) {
            $fake = new FakeTransport($handler);
            $client = new Client(Fixtures::options(['transport' => $fake, 'fail_open' => $failOpen]));

            try {
                $client->check(Fixtures::sampleEvent());
            } catch (ServerError | TimeoutError $e) {
                // fail_open = false re-raises; the call count is what matters.
            }

            self::assertSame(1, $fake->count(), "{$label}: check() must make exactly one attempt");
        }
    }

    /** AC30 (§A5 step 1) — `check()` on a closed client raises `ConfigError`. */
    public function testAc30CheckAfterCloseRaisesConfigError(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::checkSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $client->check(Fixtures::sampleEvent());
        $client->close();
        $countAfterClose = $fake->count();

        try {
            $client->check(Fixtures::sampleEvent());
            self::fail('expected a ConfigError from check() on a closed client');
        } catch (ConfigError $e) {
            self::assertStringContainsString('closed', $e->getMessage());
        }

        self::assertSame($countAfterClose, $fake->count(), 'a closed client must make no further calls');
    }
}
