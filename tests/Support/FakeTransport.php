<?php

declare(strict_types=1);

namespace SignalGate\Tests\Support;

use SignalGate\HttpResponse;
use SignalGate\PostOptions;
use SignalGate\Transport;

/**
 * Records every POST and returns scripted responses, or throws scripted errors
 * (PORTING_SPEC §A14 — "no real network in the test suite").
 *
 * Test-only scaffolding, NOT SDK implementation: it depends only on the public
 * seam (`Transport`, `PostOptions`, `HttpResponse`). Mirrors
 * `backend-node-sdk/test/helpers.ts::FakeTransport`.
 */
final class FakeTransport implements Transport
{
    /** @var list<RecordedRequest> */
    public array $requests = [];

    public bool $closed = false;

    /** @var callable(RecordedRequest): HttpResponse */
    public $handler;

    /** @param (callable(RecordedRequest): HttpResponse)|null $handler */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler ?? static fn (RecordedRequest $req): HttpResponse
            => Fixtures::logSuccessResponse($req);
    }

    /** @param array<string, mixed> $body */
    public function post(string $url, array $body, PostOptions $options): HttpResponse
    {
        $request = new RecordedRequest(
            $url,
            $body,
            $options->timeoutMs,
            $options->requestId,
            $options->idempotencyKey,
        );
        $this->requests[] = $request;

        return ($this->handler)($request);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function count(): int
    {
        return count($this->requests);
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_map(static fn (RecordedRequest $r): string => $r->url, $this->requests);
    }

    /** @return list<array<string, mixed>> */
    public function bodies(): array
    {
        return array_map(static fn (RecordedRequest $r): array => $r->body, $this->requests);
    }

    /** @return list<string> */
    public function requestIds(): array
    {
        return array_map(static fn (RecordedRequest $r): string => $r->requestId, $this->requests);
    }

    /** @return list<string> */
    public function idempotencyKeys(): array
    {
        return array_map(static fn (RecordedRequest $r): string => $r->idempotencyKey, $this->requests);
    }
}
