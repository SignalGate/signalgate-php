<?php

declare(strict_types=1);

namespace SignalGate;

use SignalGate\Errors\ServerError;

/**
 * Wire-format projection and (de)serialization (BACKEND_SDK_SPEC §4.1, §4.2,
 * §5.2; PORTING_SPEC §A3, §A5). Everything here is pure: no I/O, no state.
 */
final class Wire
{
    /** @var list<string> §4.1 top-level wire keys, in order. */
    private const EVENT_KEYS = ['user_id', 'ip', 'method', 'timestamp'];

    /** @var list<string> §4.1 `payload` wire keys, in order (minus `v`). */
    private const PAYLOAD_KEYS = ['encrypted', 'timestamp', 'nonce'];

    /** @var list<string> §4.2 known verdict actions. */
    private const KNOWN_ACTIONS = ['block', 'dry_run_block', 'admin_alert', 'allow'];

    /**
     * Projects a caller's associative event array onto the exact §4.1 wire
     * key whitelist, in order: `user_id, ip, method, timestamp, payload,
     * custom`. Any other input keys (e.g. `tenant_id`, `api_method`,
     * `verdict`) are dropped — this is the R3 outage guard applied to
     * server-owned columns.
     *
     * `custom` is included only when present and non-null. `payload.v` is
     * included only when present in `$event['payload']` and non-null,
     * forwarded verbatim. `payload` and `custom` VALUES are opaque: their
     * nested contents are forwarded unchanged.
     *
     * Assumes a well-formed event; validating required keys is the Client's
     * job, upstream of this call.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    public static function eventToWire(array $event): array
    {
        $wire = [];

        foreach (self::EVENT_KEYS as $key) {
            if (array_key_exists($key, $event)) {
                $wire[$key] = $event[$key];
            }
        }

        if (array_key_exists('payload', $event) && is_array($event['payload'])) {
            $wire['payload'] = self::payloadToWire($event['payload']);
        }

        if (array_key_exists('custom', $event) && $event['custom'] !== null) {
            // §4.1 - PHP has no native empty-object
            // literal, so `json_encode([])` always emits `[]`, never `{}`.
            // An empty `custom` map is cast to `\stdClass` so it serializes
            // as the JSON object the Node/Python ports emit; a POPULATED
            // assoc array is left untouched (§A3 opacity — AC16).
            $custom = $event['custom'];
            $wire['custom'] = (is_array($custom) && $custom === []) ? new \stdClass() : $custom;
        }

        return $wire;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function payloadToWire(array $payload): array
    {
        $wire = [];

        foreach (self::PAYLOAD_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $wire[$key] = $payload[$key];
            }
        }

        if (array_key_exists('v', $payload) && $payload['v'] !== null) {
            $wire['v'] = $payload['v'];
        }

        return $wire;
    }

    /**
     * Encodes a wire body to JSON with the exact flag combination required
     * for byte-parity with the Node/Python ports (PORTING_SPEC §A3):
     * `JSON_UNESCAPED_SLASHES` (PHP escapes `/` as `\/` by default; base64
     * `payload.encrypted` routinely contains `/`) and `JSON_UNESCAPED_UNICODE`.
     *
     * @param array<string, mixed> $body
     */
    public static function encodeBody(array $body): string
    {
        return (string) json_encode(
            $body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Parses a full decoded `/v0/check` HTTP response body into a
     * {@see CheckResult} (§4.2, §A5 `from_data`). Every field defaults
     * independently when absent — missing fields never throw.
     *
     * @param array<string, mixed> $body
     *
     * @throws ServerError with code `MALFORMED_RESPONSE` when `data` is
     *                      missing or not an array.
     */
    public static function checkResultFromData(array $body, Logger $logger): CheckResult
    {
        $data = $body['data'] ?? null;

        if (!is_array($data)) {
            throw new ServerError(
                200,
                'MALFORMED_RESPONSE',
                'malformed check response: missing or invalid data',
                (string) ($body['request_id'] ?? ''),
            );
        }

        $action = (string) ($data['action'] ?? '');

        if (!in_array($action, self::KNOWN_ACTIONS, true)) {
            $logger->warn('signalgate.check.unknown_action', ['action' => $action]);
        }

        return new CheckResult(
            $action,
            (float) ($data['score'] ?? 0.0),
            (string) ($data['request_id'] ?? ''),
            (string) ($data['tenant_id'] ?? ''),
            (string) ($data['timestamp'] ?? ''),
            (int) ($data['processing_time_us'] ?? 0),
            false,
        );
    }

    /**
     * The synthesized fail-open verdict (§6.1): an allow, at zero cost,
     * never blaming the caller for an infrastructure failure.
     */
    public static function failedOpenResult(): CheckResult
    {
        return new CheckResult('allow', 0.0, '', '', '', 0, true);
    }
}
