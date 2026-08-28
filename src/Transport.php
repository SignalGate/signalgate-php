<?php

declare(strict_types=1);

namespace SignalGate;

/**
 * The HTTP seam (PORTING_SPEC §A1, §A14 — "injected fake transport, no real
 * network in the test suite").
 *
 * This interface is part of the frozen contract: the test suite's
 * `SignalGate\Tests\Support\FakeTransport` implements it, so its signature
 * cannot change without changing the contract.
 *
 * The body is handed over as an *array*, matching the Node port
 * (`Transport.post(url, body, opts)`), so the byte-level encoding stays a
 * separately-assertable concern (`Wire::encodeBody()`), per PORTING_SPEC §A14.
 */
interface Transport
{
    /**
     * POST a JSON body and return the parsed response.
     *
     * @param array<string, mixed> $body
     *
     * @throws \SignalGate\Errors\TimeoutError deadline exceeded
     * @throws \SignalGate\Errors\NetworkError DNS/TCP/TLS/I-O failure before a response
     * @throws \SignalGate\Errors\ServerError  non-2xx carrying the error envelope
     */
    public function post(string $url, array $body, PostOptions $options): HttpResponse;

    /** Release any underlying handle. Idempotent. */
    public function close(): void;
}
