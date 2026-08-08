<?php

declare(strict_types=1);

namespace LombokClarion\Log\Formatters;

use LombokClarion\Log\LogFormatter;
use LombokClarion\Log\LogRecord;

/**
 * Human-readable single line: `2026-08-07T10:15:30+00:00 ERROR [app] message {"k":"v"}`
 *
 * For a terminal or `tail -f`, where JSON is technically better and actually
 * unreadable. Context is still appended as JSON rather than flattened into
 * prose, so the line stays greppable and nothing is silently dropped.
 *
 * Newlines in the message are escaped to keep one record on one line — a
 * stack trace pasted into a message would otherwise look like several
 * unrelated entries to whatever reads the file next.
 */
final class LineFormatter implements LogFormatter
{
    public function format(LogRecord $record): string
    {
        $line = sprintf(
            '%s %s [%s] %s',
            $record->time()->format('Y-m-d\TH:i:sP'),
            strtoupper($record->level->value),
            $record->channel,
            str_replace(["\r\n", "\n", "\r"], '\n', $record->message),
        );

        if ($record->context !== []) {
            $encoded = json_encode($record->context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $line .= ' ' . ($encoded === false ? '{"context_error":"unencodable"}' : $encoded);
        }

        return $line;
    }
}
