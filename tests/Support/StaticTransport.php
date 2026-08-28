<?php

declare(strict_types=1);

namespace SignalGate\Tests\Support;

use SignalGate\HttpResponse;
use SignalGate\PostOptions;
use SignalGate\Transport;

/**
 * A CLOSURE-FREE {@see Transport} double: it answers every POST with the same
 * canned 2xx envelope and holds no callable state at all.
 *
 * {@see FakeTransport} keeps a `callable $handler`, which makes any object
 * graph that reaches it un-`serialize()`-able ("Serialization of 'Closure' is
 * not allowed") for reasons that have nothing to do with the SDK. This double
 * exists so a Client can be assembled entirely from serializable seams — the
 * shape a DI container produces when it injects invokable service objects
 * rather than closures — and the serialized bytes can then be inspected.
 */
final class StaticTransport implements Transport
{
    public bool $closed = false;

    public int $calls = 0;

    public function post(string $url, array $body, PostOptions $options): HttpResponse
    {
        $this->calls++;

        return Fixtures::checkSuccessResponse(new RecordedRequest(
            $url,
            $body,
            $options->timeoutMs,
            $options->requestId,
            $options->idempotencyKey,
        ));
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
