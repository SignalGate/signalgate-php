<?php

declare(strict_types=1);

namespace SignalGate\Tests;

use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\CurlTransport;
use SignalGate\PostOptions;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;
use SignalGate\Wire;

/**
 * Group C — request wire format (BACKEND_SDK_SPEC §5.1/§5.2; PORTING_SPEC
 * §A3/§A4). This is the byte-level parity contract with the Node and Python
 * ports: the GOLDEN_* strings are frozen literals from the spec Appendix, an
 * independent source from the SDK's own builder.
 *
 * Covers AC11, AC12, AC13, AC14, AC15, AC16, AC17.
 */
final class WireTest extends TestCase
{
    /**
     * AC11 (§A3 Appendix golden) — the encoded body is BYTE-IDENTICAL to the
     * frozen golden with `custom` and `payload.v` present.
     */
    public function testAc11GoldenRequestBodyIsByteIdentical(): void
    {
        // Direct on the encoder.
        self::assertSame(
            Fixtures::GOLDEN_FULL,
            Wire::encodeBody(Wire::eventToWire(Fixtures::goldenEvent())),
        );

        // Wire key ORDER is contract; the caller's array order is not.
        self::assertSame(
            Fixtures::GOLDEN_FULL,
            Wire::encodeBody(Wire::eventToWire(Fixtures::goldenEventShuffled())),
        );

        // And through the real client -> transport seam.
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::checkSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));
        $client->check(Fixtures::goldenEvent());

        self::assertSame(Fixtures::GOLDEN_FULL, Wire::encodeBody($fake->requests[0]->body));
        self::assertArrayHasKey('user_id', $fake->requests[0]->body);
        self::assertArrayNotHasKey('userId', $fake->requests[0]->body);
    }

    /**
     * AC12 (§4.1, Brief R3 — THE OUTAGE GUARD) — `custom` and `payload.v`
     * vanish entirely when absent. `"v": null` on the wire routes every v2
     * payload down the dead v1 decryption path.
     */
    public function testAc12CustomAndPayloadVOmittedWhenAbsent(): void
    {
        self::assertSame(
            Fixtures::GOLDEN_OMITTED,
            Wire::encodeBody(Wire::eventToWire(Fixtures::goldenEventOmitted())),
        );

        // Explicit nulls must produce the SAME bytes - never `"v":null`.
        self::assertSame(
            Fixtures::GOLDEN_OMITTED,
            Wire::encodeBody(Wire::eventToWire(Fixtures::goldenEventExplicitNulls())),
        );

        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::checkSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));
        $client->check(Fixtures::goldenEventOmitted());

        $encoded = Wire::encodeBody($fake->requests[0]->body);
        self::assertSame(Fixtures::GOLDEN_OMITTED, $encoded);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse(array_key_exists('custom', $decoded));
        self::assertIsArray($decoded['payload']);
        self::assertFalse(array_key_exists('v', $decoded['payload']));
        self::assertStringNotContainsString('"v":null', $encoded);
        self::assertStringNotContainsString('"custom":null', $encoded);
    }

    /**
     * AC13 (§4.1, Brief R3) — `payload.v` is forwarded VERBATIM when present
     * and never defaulted.
     */
    public function testAc13PayloadVersionForwardedVerbatim(): void
    {
        foreach ([2, 1] as $version) {
            $event = Fixtures::goldenEventOmitted();
            $event['payload']['v'] = $version;

            $encoded = Wire::encodeBody(Wire::eventToWire($event));
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

            self::assertSame($version, $decoded['payload']['v']);
            self::assertStringContainsString('"v":' . $version, $encoded);
        }
    }

    /**
     * AC14 (the byte-parity trap) — `JSON_UNESCAPED_SLASHES` and
     * `JSON_UNESCAPED_UNICODE` are mandatory: PHP escapes `/` as `\/` by
     * default, whereas `JSON.stringify` and `json.dumps` do not, and
     * `payload.encrypted` is base64 that routinely contains `/`.
     */
    public function testAc14SlashesAndUnicodeAreNotEscaped(): void
    {
        $event = Fixtures::sampleEvent([
            'payload' => Fixtures::samplePayload(['encrypted' => 'a/b+c=/d==']),
            'custom' => ['note' => 'привет'],
        ]);

        $encoded = Wire::encodeBody(Wire::eventToWire($event));

        self::assertStringContainsString('"encrypted":"a/b+c=/d=="', $encoded);
        self::assertStringNotContainsString('a\\/b', $encoded);
        self::assertStringContainsString('"note":"привет"', $encoded);
        self::assertStringNotContainsString('\\u043f', $encoded);
    }

    /**
     * AC15 (§5.1, §A4) — endpoint + the exact five headers, with a fresh
     * UUIDv4 `X-Request-Id` and a distinct UUIDv4 `Idempotency-Key`.
     *
     * Brief R2: the header is `X-Request-Id`. `X-Signalgate-Request-Id` (§6.3
     * prose) silently breaks server log correlation and must never be sent.
     *
     * CONTRACT SEAM: `CurlTransport::buildHeaders(string $apiKey, PostOptions
     * $options): array` — a header name => value map. The production transport
     * cannot otherwise be observed without a network call, and AC15/AC53
     * both assert against "the header array the transport builds".
     */
    public function testAc15EndpointAndHeaders(): void
    {
        $apiKey = 'jwt-test-key-abc';
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::checkSuccessResponse($r));
        $client = new Client(Fixtures::options(['api_key' => $apiKey, 'transport' => $fake]));

        $client->check(Fixtures::sampleEvent());

        self::assertSame(1, $fake->count());
        $recorded = $fake->requests[0];
        self::assertSame('https://api.signalgate.ai/v0/check', $recorded->url);

        self::assertMatchesRegularExpression(Fixtures::UUID_V4_PATTERN, $recorded->requestId);
        self::assertMatchesRegularExpression(Fixtures::UUID_V4_PATTERN, $recorded->idempotencyKey);
        self::assertNotSame($recorded->requestId, $recorded->idempotencyKey);

        $headers = CurlTransport::buildHeaders(
            $apiKey,
            new PostOptions($recorded->timeoutMs, $recorded->requestId, $recorded->idempotencyKey),
        );

        $names = array_keys($headers);
        sort($names);
        self::assertSame(
            ['Authorization', 'Content-Type', 'Idempotency-Key', 'User-Agent', 'X-Request-Id'],
            $names,
        );
        self::assertSame('Bearer ' . $apiKey, $headers['Authorization']);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertStringStartsWith('signalgate-backend-sdk/0.1.0 (php/', $headers['User-Agent']);
        self::assertSame($recorded->requestId, $headers['X-Request-Id']);
        self::assertSame($recorded->idempotencyKey, $headers['Idempotency-Key']);
        self::assertArrayNotHasKey('X-Signalgate-Request-Id', $headers);
    }

    /**
     * AC16 (§5.2, §A3) — `payload` and `custom` are OPAQUE: forwarded verbatim
     * with no coercion, no reordering, no stripping.
     */
    public function testAc16PayloadAndCustomAreOpaque(): void
    {
        $custom = ['plan' => 'pro', 'nested' => ['a' => [1, 2, 3], 'b' => 'x'], 'n' => 0, 'f' => false];
        $payload = Fixtures::samplePayload(['encrypted' => 'OPAQUE++/blob==', 'v' => 2]);

        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::checkSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));
        $client->check(Fixtures::sampleEvent(['payload' => $payload, 'custom' => $custom]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            Wire::encodeBody($fake->requests[0]->body),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($custom, $decoded['custom']);
        self::assertSame($payload, $decoded['payload']);
    }

    /**
     * AC17 (§A3, §9 row 9) — server-owned keys are stripped by an explicit
     * whitelist, on BOTH the check and the log path. `tenant_id` comes from
     * the JWT; `api_method` and `verdict` are server-set analytics columns.
     */
    public function testAc17ServerOwnedKeysNeverReachTheWire(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => str_ends_with($r->url, '/check')
            ? Fixtures::checkSuccessResponse($r)
            : Fixtures::logSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));

        $event = Fixtures::sampleEvent([
            'tenant_id' => 'acme',
            'api_method' => 'check',
            'verdict' => 'allow',
        ]);

        $client->check($event);
        $client->log($event);
        $client->flush();

        self::assertSame(2, $fake->count());
        foreach ($fake->requests as $index => $recorded) {
            self::assertFalse(
                array_key_exists('tenant_id', $recorded->body),
                "request #{$index} leaked tenant_id",
            );
            self::assertFalse(
                array_key_exists('api_method', $recorded->body),
                "request #{$index} leaked api_method",
            );
            self::assertFalse(
                array_key_exists('verdict', $recorded->body),
                "request #{$index} leaked verdict",
            );
            self::assertSame(
                ['user_id', 'ip', 'method', 'timestamp', 'payload', 'custom'],
                array_keys($recorded->body),
                "request #{$index} must carry exactly the §4.1 wire keys, in order",
            );
        }
    }
}
