# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Pre-1.0 caveat: while the major version is `0`, breaking changes may land in
minor versions.

## [0.1.0] - 2026-08-27

Initial public release.

### Added

- `Client` with the two-call API: `check()` (synchronous verdict via
  `/v0/check`) and `log()` (fire-and-forget analytics via `/v0/log`).
- Fail-open behavior for `check()` on timeout / network error / 5xx
  (`fail_open: true` by default), returning a synthesized
  `CheckResult{action: "allow", failed_open: true}`.
- PHP-FPM-aware log delivery: `log()` only ever appends to a bounded
  in-memory buffer (drop-oldest on overflow); the buffer is drained — with
  retries and exponential backoff — by `flush()`, by `close()`, or by a
  `register_shutdown_function` hook that runs after the HTTP response has
  already been sent, so the retry ladder costs the end user zero latency.
- Error hierarchy: `SignalGateError`, `ConfigError`, `TimeoutError`,
  `NetworkError`, `ServerError`.
- Built-in `Metrics` counters for check/log outcomes
  (`$client->metrics()->get(...)` / `->snapshot()`).
- PHP >= 8.1, zero runtime dependencies (`ext-curl` and `ext-json` only).
- Family-standard `User-Agent`:
  `signalgate-backend-sdk/<version> (php/<php_version>; <os>)`, sharing the
  `signalgate-backend-sdk/` prefix with the Go, Node, Python and Java ports
  so the server can group traffic by SDK version.

[0.1.0]: https://github.com/SignalGate/signalgate-php/releases/tag/v0.1.0
