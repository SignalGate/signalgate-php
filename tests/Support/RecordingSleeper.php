<?php

declare(strict_types=1);

namespace SignalGate\Tests\Support;

/**
 * The injected `sleeper` seam: `callable(int $ms): void`.
 *
 * Records the requested backoff and returns IMMEDIATELY — the suite must never
 * actually sleep (AC42's ladder is asserted on the recorded values).
 */
final class RecordingSleeper
{
    /** @var list<int> */
    public array $sleeps = [];

    public function __invoke(int $ms): void
    {
        $this->sleeps[] = $ms;
    }
}
