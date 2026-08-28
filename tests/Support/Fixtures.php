<?php

declare(strict_types=1);

namespace SignalGate\Tests\Support;

use SignalGate\HttpResponse;

/**
 * Frozen golden values + scripted envelope builders.
 *
 * The GOLDEN_* strings are copied VERBATIM from an independent source — the
 * `PORTING_SPEC.md` §Appendix "golden example" (and, identically, the frozen
 * literals in `backend-node-sdk/test/wire.test.ts:21-29`). They are NEVER
 * regenerated from the PHP SDK's own body builder: a byte-parity test that
 * asserts an encoder against its own output proves nothing.
 *
 * The golden *input* arrays below are likewise hand-transcribed from the
 * §Appendix prose bullet list, not decoded out of the golden strings.
 */
final class Fixtures
{
    /**
     * PORTING_SPEC §Appendix — golden request body with `custom` and
     * `payload.v` PRESENT. `payload.v` is load-bearing (Brief R3): the server
     * routes v1-vs-v2 decryption on it and dropping it caused a production
     * outage.
     */
    public const GOLDEN_FULL = '{"user_id":"u_123","ip":"203.0.113.7","method":"login","timestamp":"2026-04-01T13:08:50+00:00","payload":{"encrypted":"BASE64","timestamp":1748102400000,"nonce":"aZ19bCde3fGhI4jK","v":2},"custom":{"plan":"pro"}}';

    /**
     * PORTING_SPEC §Appendix — the same event with `custom` and `payload.v`
     * absent/null: BOTH keys disappear entirely (never `"v":null`).
     */
    public const GOLDEN_OMITTED = '{"user_id":"u_123","ip":"203.0.113.7","method":"login","timestamp":"2026-04-01T13:08:50+00:00","payload":{"encrypted":"BASE64","timestamp":1748102400000,"nonce":"aZ19bCde3fGhI4jK"}}';

    /** PORTING_SPEC §Appendix — golden `check` success envelope. */
    public const GOLDEN_CHECK_ENVELOPE = '{"data":{"action":"allow","score":0.0,"request_id":"req_1","tenant_id":"acme","timestamp":"2026-04-01T13:08:50Z","processing_time_us":812}}';

    /** BACKEND_SDK_SPEC §5.1 / §A4 — both ID headers are UUIDv4. */
    public const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public const CHECK_URL = 'https://api.signalgate.ai/v0/check';

    public const LOG_URL = 'https://api.signalgate.ai/v0/log';

    /**
     * The §Appendix golden event, transcribed from the spec's prose bullets.
     *
     * @return array<string, mixed>
     */
    public static function goldenEvent(): array
    {
        return [
            'user_id' => 'u_123',
            'ip' => '203.0.113.7',
            'method' => 'login',
            'timestamp' => '2026-04-01T13:08:50+00:00',
            'payload' => [
                'encrypted' => 'BASE64',
                'timestamp' => 1748102400000,
                'nonce' => 'aZ19bCde3fGhI4jK',
                'v' => 2,
            ],
            'custom' => ['plan' => 'pro'],
        ];
    }

    /**
     * The same golden event with the caller's keys in a DIFFERENT order.
     *
     * PHP assoc arrays preserve insertion order, so this is the guard against
     * an implementation that just `json_encode`s the caller's array: the wire
     * key order is contract (§A3), the caller's is not.
     *
     * @return array<string, mixed>
     */
    public static function goldenEventShuffled(): array
    {
        return [
            'custom' => ['plan' => 'pro'],
            'timestamp' => '2026-04-01T13:08:50+00:00',
            'payload' => [
                'v' => 2,
                'nonce' => 'aZ19bCde3fGhI4jK',
                'encrypted' => 'BASE64',
                'timestamp' => 1748102400000,
            ],
            'method' => 'login',
            'ip' => '203.0.113.7',
            'user_id' => 'u_123',
        ];
    }

    /**
     * The §Appendix golden event with `custom` and `payload.v` simply absent.
     *
     * @return array<string, mixed>
     */
    public static function goldenEventOmitted(): array
    {
        return [
            'user_id' => 'u_123',
            'ip' => '203.0.113.7',
            'method' => 'login',
            'timestamp' => '2026-04-01T13:08:50+00:00',
            'payload' => [
                'encrypted' => 'BASE64',
                'timestamp' => 1748102400000,
                'nonce' => 'aZ19bCde3fGhI4jK',
            ],
        ];
    }

    /**
     * The §Appendix golden event with `custom` and `payload.v` explicitly null.
     *
     * @return array<string, mixed>
     */
    public static function goldenEventExplicitNulls(): array
    {
        return [
            'user_id' => 'u_123',
            'ip' => '203.0.113.7',
            'method' => 'login',
            'timestamp' => '2026-04-01T13:08:50+00:00',
            'payload' => [
                'encrypted' => 'BASE64',
                'timestamp' => 1748102400000,
                'nonce' => 'aZ19bCde3fGhI4jK',
                'v' => null,
            ],
            'custom' => null,
        ];
    }

    /**
     * Ordinary sample payload (§4.1 wire keys — PHP takes assoc arrays, so
     * there is no camelCase mapping layer).
     *
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function samplePayload(array $overrides = []): array
    {
        return array_merge([
            'encrypted' => 'BASE64BLOB==',
            'timestamp' => 1745251200000,
            'nonce' => 'aZ19bCde3fGhI4jK',
        ], $overrides);
    }

    /**
     * Ordinary sample event.
     *
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function sampleEvent(array $overrides = []): array
    {
        return array_merge([
            'user_id' => 'u_123',
            'ip' => '203.0.113.42',
            'method' => 'login',
            'timestamp' => '2026-04-01T13:08:50+00:00',
            'payload' => self::samplePayload(),
            'custom' => ['plan' => 'pro'],
        ], $overrides);
    }

    /**
     * Baseline constructor options: every test injects its seams and disables
     * the shutdown hook so no handler leaks between cases.
     *
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function options(array $overrides = []): array
    {
        return array_merge([
            'api_key' => 'jwt-test',
            'register_shutdown' => false,
        ], $overrides);
    }

    /**
     * §5.2 success envelope.
     *
     * @return array<string, mixed>
     */
    public static function successEnvelope(mixed $data, string $requestId): array
    {
        return ['ok' => true, 'request_id' => $requestId, 'error' => null, 'data' => $data];
    }

    /**
     * §5.3 error envelope.
     *
     * @param  array<string, mixed>|null $details
     * @return array<string, mixed>
     */
    public static function errorEnvelope(
        string $code,
        string $message,
        string $requestId,
        ?array $details = null,
    ): array {
        return [
            'ok' => false,
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $requestId,
                'details' => $details,
            ],
        ];
    }

    /** The §Appendix golden check envelope, decoded. @return array<string, mixed> */
    public static function goldenCheckEnvelope(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::GOLDEN_CHECK_ENVELOPE, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** A 2xx `/v0/check` envelope whose `data` echoes the request (§A3, §A5). */
    public static function checkSuccessResponse(
        RecordedRequest $req,
        string $action = 'allow',
    ): HttpResponse {
        $scores = ['allow' => 0.0, 'admin_alert' => 0.25, 'dry_run_block' => 0.5, 'block' => 1.0];

        return new HttpResponse(
            200,
            self::successEnvelope([
                'action' => $action,
                'score' => $scores[$action] ?? 0.0,
                'request_id' => $req->requestId,
                'tenant_id' => 't_fake',
                'timestamp' => $req->body['timestamp'] ?? '',
                'processing_time_us' => 42,
            ], $req->requestId),
            $req->requestId,
        );
    }

    /** A 2xx `/v0/log` ack (body ignored by the SDK, §A3). */
    public static function logSuccessResponse(RecordedRequest $req): HttpResponse
    {
        return new HttpResponse(200, self::successEnvelope(null, $req->requestId), $req->requestId);
    }

    /**
     * A 2xx response carrying an arbitrary raw body (for the §A5 step-6
     * malformed-response cases).
     *
     * @param array<string, mixed> $body
     */
    public static function rawResponse(array $body, string $requestId = 'req_raw'): HttpResponse
    {
        return new HttpResponse(200, $body, $requestId);
    }

    /**
     * Every `.php` file shipped under `src/` — the surface the security scans
     * (AC54, AC56) walk.
     *
     * @return list<string>
     */
    public static function shippedSources(): array
    {
        $root = dirname(__DIR__, 2) . "/src";
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === "php") {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
