<?php

declare(strict_types=1);

namespace SignalGate;

/**
 * Labeled counters (BACKEND_SDK_SPEC §7; PORTING_SPEC §A9). A counter's
 * identity is its name plus the UNORDERED set of its label pairs: the order
 * labels are passed at call time never affects which counter is incremented.
 *
 * `snapshotFlat()` renders Prometheus-style keys with labels sorted
 * alphabetically by key, comma-joined, e.g. `x_total{a="1",b="2"}`; a counter
 * with no labels renders as just its bare name, e.g. `check_total`.
 */
final class Metrics
{
    /**
     * Keyed by an internal identity string (name + sorted labels); each
     * entry retains the original label map (sorted) for rendering.
     *
     * @var array<string, array{name: string, labels: array<string, string>, value: int}>
     */
    private array $counters = [];

    public function __construct()
    {
    }

    /** @param array<string, string> $labels */
    public function inc(string $name, array $labels = []): void
    {
        ksort($labels);
        $key = self::identity($name, $labels);

        if (!isset($this->counters[$key])) {
            $this->counters[$key] = ['name' => $name, 'labels' => $labels, 'value' => 0];
        }

        $this->counters[$key]['value']++;
    }

    /** @param array<string, string> $labels */
    public function get(string $name, array $labels = []): int
    {
        ksort($labels);
        $key = self::identity($name, $labels);

        return $this->counters[$key]['value'] ?? 0;
    }

    /**
     * @return array<string, array{name: string, labels: array<string, string>, value: int}>
     */
    public function snapshot(): array
    {
        return $this->counters;
    }

    /** @return array<string, int> */
    public function snapshotFlat(): array
    {
        $flat = [];
        foreach ($this->counters as $counter) {
            $flat[self::renderFlatKey($counter['name'], $counter['labels'])] = $counter['value'];
        }

        return $flat;
    }

    /** @param array<string, string> $sortedLabels already `ksort`-ed */
    private static function identity(string $name, array $sortedLabels): string
    {
        return $name . "\0" . serialize($sortedLabels);
    }

    /** @param array<string, string> $sortedLabels already `ksort`-ed */
    private static function renderFlatKey(string $name, array $sortedLabels): string
    {
        if ($sortedLabels === []) {
            return $name;
        }

        $parts = [];
        foreach ($sortedLabels as $k => $v) {
            $parts[] = $k . '="' . $v . '"';
        }

        return $name . '{' . implode(',', $parts) . '}';
    }
}
