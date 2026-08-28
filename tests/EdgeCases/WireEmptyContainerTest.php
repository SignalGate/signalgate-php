<?php

declare(strict_types=1);

namespace SignalGate\Tests\EdgeCases;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\Errors\ConfigError;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;
use SignalGate\Wire;

/**
 * Hardening amendment — the two container-shaped holes in the §4.1 wire
 * projection.
 *
 * AC63: PHP has no native empty-object literal. `json_encode([])` always emits
 * the JSON ARRAY `[]`, never `{}`, so a caller who passes `custom => []` (a
 * tenant that always attaches a custom map, sometimes empty) puts
 * `"custom":[]` on the wire where the Node and Python ports — whose empty
 * dict/object types serialize natively — put `"custom":{}`. That is a
 * byte-parity break against the Brief's top-line requirement, on a field the
 * server parses as an object.
 *
 * AC64: `check(['payload' => 'not-an-array', ...])` clears the Client's
 * presence-only key check (the key IS there) and then loses the whole
 * `payload` key in the projection — the request reaches the server with no
 * payload at all, defeating the antifraud check entirely, and the caller is
 * told nothing.
 *
 * Covers AC63, AC64.
 */
final class WireEmptyContainerTest extends TestCase
{
    /** A payload value that must never be echoed back to the caller (§8.4). */
    private const PAYLOAD_SECRET = 'card=4111111111111111';

    /**
     * AC63 (§4.1, §A3 — byte parity with the Node/Python ports) — an EMPTY
     * `custom` map serializes as `{}`, and a populated one still serializes as
     * the object it always was (the guard against a fix that casts every
     * `custom` to `stdClass` and mangles nested lists).
     */
    public function testAc63EmptyCustomSerializesAsAJsonObjectNotAJsonArray(): void
    {
        $empty = Fixtures::sampleEvent();
        $empty['custom'] = [];

        $bytes = Wire::encodeBody(Wire::eventToWire($empty));

        self::assertStringContainsString(
            '"custom":{}',
            $bytes,
            'an empty custom map must serialize as a JSON object, matching the Node and Python ports',
        );
        self::assertStringNotContainsString('"custom":[]', $bytes);

        $populated = Fixtures::sampleEvent();
        $populated['custom'] = ['a' => 1];

        self::assertStringContainsString(
            '"custom":{"a":1}',
            Wire::encodeBody(Wire::eventToWire($populated)),
            'a populated custom map must be untouched by the empty-map fix',
        );
    }

    /**
     * AC64 (§4.1, §A5 step 1) — a `payload` that is not a map is a caller bug
     * and must raise a `ConfigError` naming the offending key, not vanish from
     * the wire. The rejected VALUE never appears in the message (§8.4), and
     * nothing is sent.
     */
    public function testAc64NonArrayPayloadIsRejectedInsteadOfSilentlyDropped(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::checkSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));

        try {
            $client->check(Fixtures::sampleEvent(['payload' => self::PAYLOAD_SECRET]));
            self::fail('expected a ConfigError for a payload that is not a map');
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            self::assertInstanceOf(
                ConfigError::class,
                $e,
                sprintf('a non-map payload must raise a ConfigError, not a %s', get_class($e)),
            );
            self::assertStringContainsString('payload', $e->getMessage(), 'the message must name the offending key');
            self::assertStringNotContainsString(self::PAYLOAD_SECRET, $e->getMessage());
        }

        self::assertSame(0, $fake->count(), 'a rejected event must never reach the transport');
    }
}
