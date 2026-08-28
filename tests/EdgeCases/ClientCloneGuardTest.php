<?php

declare(strict_types=1);

namespace SignalGate\Tests\EdgeCases;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use SignalGate\Client;
use SignalGate\Tests\Support\FakeTransport;
use SignalGate\Tests\Support\Fixtures;
use SignalGate\Tests\Support\RecordedRequest;

/**
 * Hardening amendment — a Client cannot be cloned.
 *
 * PHP's default shallow `clone` would hand back a sibling that silently
 * shares the original's transport, `Metrics` and log buffer while owning its
 * own copy of the `closed` flag: `close()` on one leaves the other believing
 * it is open on top of an already-released handle, and a sibling cloned
 * before the buffer is created lazily builds a SECOND buffer over the same
 * transport, breaking "exactly one LogBuffer per Client" (§8.3, AC55).
 *
 * There is no correct shallow copy of an object that owns a connection and a
 * delivery queue, so the operation is refused outright. The refusal is an
 * engine-level `\Error` (a non-public `__clone`), deliberately NOT an
 * exception: caller code with a broad `catch (\Exception)` around its wiring
 * must not be able to swallow it and carry on with a half-working Client.
 *
 * Covers AC65.
 */
final class ClientCloneGuardTest extends TestCase
{
    /** AC65 (§3.1, §8.3) — `clone $client` is refused, and the original is unharmed. */
    public function testAc65CloningAClientIsRefused(): void
    {
        $fake = new FakeTransport(static fn (RecordedRequest $r) => Fixtures::checkSuccessResponse($r));
        $client = new Client(Fixtures::options(['transport' => $fake]));

        try {
            $sibling = clone $client;
            self::fail(sprintf(
                'clone must be refused; got a second %s silently sharing the original transport, metrics and buffer',
                get_class($sibling),
            ));
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (\Error $e) {
            // Refused at the engine level, as required.
        }

        $result = $client->check(Fixtures::sampleEvent());

        self::assertSame('allow', $result->action, 'the refused clone must leave the original fully usable');
        self::assertSame(1, $fake->count());
        self::assertSame(1, $client->metrics()->get('check_success_total'));
    }
}
