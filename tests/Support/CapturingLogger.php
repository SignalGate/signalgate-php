<?php

declare(strict_types=1);

namespace SignalGate\Tests\Support;

use SignalGate\Logger;

/**
 * Records every log line so the redaction assertions (§8.4, §A14 row 15) can
 * inspect them. Mirrors `backend-node-sdk/test/helpers.ts::CapturingLogger`.
 */
final class CapturingLogger implements Logger
{
    /** @var list<array{level: string, msg: string, fields: array<string, mixed>}> */
    public array $entries = [];

    /** @param array<string, mixed> $fields */
    public function debug(string $message, array $fields = []): void
    {
        $this->record('debug', $message, $fields);
    }

    /** @param array<string, mixed> $fields */
    public function info(string $message, array $fields = []): void
    {
        $this->record('info', $message, $fields);
    }

    /** @param array<string, mixed> $fields */
    public function warn(string $message, array $fields = []): void
    {
        $this->record('warn', $message, $fields);
    }

    /** @param array<string, mixed> $fields */
    public function error(string $message, array $fields = []): void
    {
        $this->record('error', $message, $fields);
    }

    /** Full serialization of every captured entry (message + fields). */
    public function serialized(): string
    {
        $json = json_encode(
            $this->entries,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        // Fall back to a lossy dump rather than losing the redaction assertion.
        return $json === false ? print_r($this->entries, true) : $json;
    }

    /**
     * @return list<array{level: string, msg: string, fields: array<string, mixed>}>
     */
    public function atLevel(string $level): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['level'] === $level,
        ));
    }

    /** @return list<string> */
    public function messagesAtLevel(string $level): array
    {
        return array_map(
            static fn (array $entry): string => $entry['msg'],
            $this->atLevel($level),
        );
    }

    /** @param array<string, mixed> $fields */
    private function record(string $level, string $message, array $fields): void
    {
        $this->entries[] = ['level' => $level, 'msg' => $message, 'fields' => $fields];
    }
}
