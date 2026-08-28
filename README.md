# signalgate/signalgate-php — PHP backend SDK

[![PHP 8.1+](https://img.shields.io/badge/php-8.1+-8892BF.svg)](https://www.php.net/)
[![License: Apache 2.0](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)

Thin HTTP forwarder for [SignalGate](https://signalgate.ai) antifraud.
Tenants embed this in their backend to call `/v0/check` (synchronous verdict)
and `/v0/log` (async analytics). The SDK does **no crypto** — the opaque
encrypted payload comes from the SignalGate frontend SDK.

## Install

```bash
composer require signalgate/signalgate-php
```

Requires PHP >= 8.1 with the `curl` and `json` extensions (bundled with almost
every PHP install). No Composer dependencies beyond `php-http`-free core PHP —
this package has **zero runtime dependencies**.

## Quickstart

`check()` and `log()` are **two calls at two points in your funnel**, not two
ways to send one event:

- **`check()` — *before* the action you're gating.** Synchronous; returns a
  verdict so you can block or allow. The server records the check for
  analytics.
- **`log()` — *after* the action completes.** Fire-and-forget telemetry; no
  verdict. Returns immediately — it only appends to an in-memory buffer, it
  never does I/O on the hot path.

A typical flow calls **both**:

```php
use SignalGate\Client;
use SignalGate\Errors\ServerError;

$client = new Client([
    'api_key' => 'pk_live_...',  // API key minted by the SignalGate dashboard
]);

// 1. BEFORE the gated action — get a verdict and gate on it.
$gateEvent = [
    'user_id' => 'u_123',
    'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'],
    'method' => 'checkout',
    'timestamp' => '2026-04-01T13:08:50+00:00',
    'payload' => [
        'encrypted' => '...',
        'timestamp' => 1748102400000,
        'nonce' => '...',
        'v' => 2,
    ],
    'custom' => ['plan' => 'pro'],
];

try {
    $verdict = $client->check($gateEvent);
} catch (ServerError $e) {
    // Handle errors; metrics captured automatically
    error_log("check failed: {$e->getMessage()}");
}

if (($verdict->action ?? null) === 'block') {
    throw new \RuntimeException('blocked by SignalGate');
}

chargeTheCustomer();  // your paid action

// 2. AFTER the action completes — log it (fire-and-forget analytics).
$client->log($doneEvent);  // a fresh event captured at this funnel point
```

You can also call `log()` on its own for actions you aren't gating — background
jobs, downstream action completions, page-view telemetry, etc.

> **Distinct events per call.** `check` and `log` fire at different moments, so
> each carries its own event with its own fingerprint payload (its own
> `nonce`). Don't forward the byte-identical payload to both back-to-back — the
> server's nonce-freshness guard would replay-reject the second.

At the end of a script or long-running process:

```php
$client->close();  // drains the log queue, releases the cURL handle
```

Or use try/finally for guaranteed cleanup:

```php
$client = new Client(['api_key' => 'pk_live_...']);
try {
    // ... use $client
} finally {
    $client->close();
}
```

Under normal PHP-FPM request handling you don't need to call `close()`
yourself — by default the `Client` registers a shutdown hook (see below) that
drains the log buffer for you when the request ends.

## PHP runtime notes

PHP-FPM has no threads and no event loop, so `log()` cannot hand delivery off
to a background worker the way the Node port does. Instead `log()` only
appends to a bounded in-memory buffer on the `Client`; the buffer is drained —
including the full retry ladder — at one of three points:

- **`$client->flush()`** — explicit, synchronous drain. Call it whenever you
  want delivery to happen right now (CLI scripts, tests, before a long sleep).
- **The shutdown hook (the FPM default).** By default (`register_shutdown =>
  true`) the `Client` registers a `register_shutdown_function` closure that
  calls `close()`, which drains the buffer. Under php-fpm, **call
  `fastcgi_finish_request()` in your own request handler before your script
  ends** — it flushes the HTTP response to the client immediately, and PHP
  keeps running your shutdown functions afterward. Because the SDK's drain
  (including the 200/400/800 ms retry ladder) runs inside that
  post-response phase, it costs your end user **zero added latency**. The SDK
  never calls `fastcgi_finish_request()` for you — only your own handler code
  knows it's safe to end the response there.
- **`$client->close()`** — same drain, plus it releases the cURL handle. Safe
  to call multiple times; only the first call does any work.

> **Sizing the drain against your worker pool.** Once
> `fastcgi_finish_request()` has run, the response is already sent and php-fpm's
> `request_terminate_timeout` no longer applies to what follows — so the drain
> is bounded only by the SDK's own deadline, `5 × log_timeout_ms` (5 s at the
> default `log_timeout_ms => 1000`). That is the worst case while SignalGate is
> unreachable: a worker stays busy draining for up to that long after
> responding, and anything still undelivered when the deadline passes is
> counted as `log_dropped_total{reason="closed"}` rather than being retried
> forever. If you raise `log_timeout_ms`, you raise that ceiling with it — on a
> small `pm.max_children` pool, a long ceiling plus a degraded endpoint means
> fewer workers free to accept new requests. The defaults are sized so this
> stays well inside a typical pool; verified under real php-fpm on PHP 8.1–8.4.

Long-lived runtimes without a per-request shutdown boundary — Swoole,
RoadRunner, FrankenPHP, Laravel Octane, or a plain CLI worker loop — should
call `$client->flush()` periodically (for example, once per request/job
inside the worker loop) instead of relying on the shutdown hook, since the PHP
process itself never "shuts down" between units of work.

## Configuration

Override the defaults selectively. All options are snake_case, matching the
wire-level and cross-SDK naming:

```php
$client = new Client([
    'api_key' => '...',
    'check_timeout_ms' => 3000,
    'log_timeout_ms' => 1000,
    'log_queue_capacity' => 10000,
    'log_max_retries' => 3,
    'log_retry_base_ms' => 200,
    'fail_open' => true,  // default: on timeout/5xx, check() returns allow
]);
```

| Option | Type | Default | Meaning |
|---|---|---|---|
| `api_key` | `string` | — | **Required.** Non-empty API key minted by the SignalGate dashboard. |
| `check_timeout_ms` | `int` | `3000` | Per-request timeout for `check()`. |
| `log_timeout_ms` | `int` | `1000` | Per-request timeout for each log delivery attempt. |
| `log_queue_capacity` | `int` | `10000` | Bounded buffer size for `log()`; overflow drops the oldest entry. |
| `log_max_retries` | `int` | `3` | Retries **after** the first attempt (⇒ 4 attempts max). |
| `log_retry_base_ms` | `int` | `200` | Base for exponential backoff (200, 400, 800 ms). |
| `fail_open` | `bool` | `true` | On transient error `check()` returns a synthesized allow instead of throwing. |
| `transport` | `Transport\|null` | real cURL transport | Test/advanced seam — inject your own `SignalGate\Transport`. |
| `logger` | `Logger\|null` | `SignalGate\NoopLogger` | Inject a PSR-3-shaped logger to observe warnings/errors. |
| `sleeper` | `callable(int): void\|null` | `usleep`-based | Test seam standing in for the backoff sleep between retries. |
| `register_shutdown` | `bool` | `true` | Whether the constructor registers the `close()`-on-shutdown hook. Set `false` in tests so handlers don't leak across cases. |

## Error handling

`check()` raises on 4xx (tenant bug — bad/revoked API key, malformed event).
With `fail_open = true` (default), timeouts / network errors / 5xx return a
synthesized `CheckResult{action: "allow", failed_open: true}` instead of
throwing.

```php
use SignalGate\Errors\ConfigError;
use SignalGate\Errors\NetworkError;
use SignalGate\Errors\ServerError;
use SignalGate\Errors\SignalGateError;
use SignalGate\Errors\TimeoutError;

try {
    $verdict = $client->check($event);
} catch (ServerError $e) {
    // $e->statusCode, $e->code, $e->serverMessage, $e->requestId, $e->details
    error_log("server error: [{$e->statusCode}] {$e->getMessage()}");
} catch (TimeoutError | NetworkError $e) {
    // Only reachable with fail_open => false.
    error_log("transport error: {$e->getMessage()}");
} catch (ConfigError $e) {
    // Bad constructor config, malformed event, or client already closed.
    error_log("config error: {$e->getMessage()}");
} catch (SignalGateError $e) {
    // Catch-all for the whole error hierarchy.
    error_log("signalgate error: {$e->getMessage()}");
}
```

Error types (all extend `SignalGate\Errors\SignalGateError`, itself a
`\RuntimeException`):

- `ConfigError` — bad constructor config, malformed event, or client already closed.
- `TimeoutError` — request deadline exceeded.
- `NetworkError` — DNS/TCP/TLS/I-O failure before a response.
- `ServerError` — non-2xx response carrying the error envelope.

`log()` never throws. Failures are counted in `$client->metrics()`.

## Metrics

```php
$client->metrics()->get('check_total');
$client->metrics()->get('check_failed_open_total');
$client->metrics()->get('log_dropped_total', ['reason' => 'queue_full']);
$snapshot = $client->metrics()->snapshot();  // all counters
```

Full counter list:

| Counter | Labels | Incremented when |
|---|---|---|
| `check_total` | — | `check()` called |
| `check_success_total` | — | `check()` returned a real verdict |
| `check_failed_open_total` | — | `check()` returned a synthesized allow |
| `check_error_total` | `type` | `check()` raised |
| `log_enqueued_total` | — | `log()` accepted into the buffer |
| `log_sent_total` | — | server acknowledged a log event |
| `log_http_error_total` | `status` | a log delivery attempt failed |
| `log_dropped_total` | `reason` | `queue_full` / `closed` / `retry_exhausted` |

## Development

```bash
composer install         # install dependencies
composer test            # run the test suite (vendor/bin/phpunit)
```

All tests use an injected fake transport — no real network calls in the test
suite and no API key required.

## License

Licensed under the [Apache License, Version 2.0](LICENSE).

## Links

- Homepage: <https://signalgate.ai>
- Documentation: <https://signalgate.ai/docs/php>
