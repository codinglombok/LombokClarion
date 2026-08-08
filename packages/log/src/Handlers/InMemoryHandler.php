<?php

declare(strict_types=1);

namespace LombokClarion\Log\Handlers;

use LombokClarion\Log\LogHandler;
use LombokClarion\Log\LogLevel;
use LombokClarion\Log\LogRecord;

/**
 * Keeps records in memory so a test can assert on what was logged.
 *
 * This ships in the framework rather than being re-hand-rolled in each app's
 * test suite, because "did this code log the thing" is a real assertion and
 * the alternative is asserting on file contents — which means tests that touch
 * disk, race under parallel runs, and fail for reasons unrelated to logging.
 */
final class InMemoryHandler implements LogHandler
{
    /** @var list<LogRecord> */
    private array $records = [];

    public function __construct(private readonly LogLevel $minimumLevel = LogLevel::Debug)
    {
    }

    public function handle(LogRecord $record): void
    {
        if (!$record->level->isAtLeast($this->minimumLevel)) {
            return;
        }

        $this->records[] = $record;
    }

    /** @return list<LogRecord> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<LogRecord> */
    public function recordsAt(LogLevel $level): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (LogRecord $r): bool => $r->level === $level
        ));
    }

    public function hasRecordMatching(string $needle): bool
    {
        foreach ($this->records as $record) {
            if (str_contains($record->message, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
