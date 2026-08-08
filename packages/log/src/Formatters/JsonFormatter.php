<?php

declare(strict_types=1);

namespace LombokClarion\Log\Formatters;

use LombokClarion\Log\LogFormatter;
use LombokClarion\Log\LogRecord;

/**
 * One record, one line of JSON — the shape log shippers and aggregators
 * already parse without a custom grok pattern.
 *
 * Newlines inside the payload are escaped by json_encode itself, so a
 * multi-line message or a stack trace in context cannot break the
 * one-record-per-line invariant the reader depends on. That property is why
 * this is the default formatter for files rather than the prettier line
 * format: a log that becomes unparseable exactly when something is going
 * wrong is a log that fails when you need it.
 */
final class JsonFormatter implements LogFormatter
{
    public function format(LogRecord $record): string
    {
        $payload = [
            'time' => $record->time()->format('Y-m-d\TH:i:s.up'),
            'level' => $record->level->value,
            'channel' => $record->channel,
            'message' => $record->message,
        ];

        if ($record->context !== []) {
            $payload['context'] = $record->context;
        }

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        // PARTIAL_OUTPUT_ON_ERROR above already keeps malformed UTF-8 or a
        // resource in context from nulling the whole line. If encoding still
        // fails outright, emit a valid line saying so rather than writing
        // nothing: a log entry that vanishes silently is the failure mode this
        // whole class exists to avoid.
        if ($encoded === false) {
            return json_encode([
                'time' => $record->time()->format('Y-m-d\TH:i:s.up'),
                'level' => $record->level->value,
                'channel' => $record->channel,
                'message' => $record->message,
                'context_error' => 'context could not be encoded as JSON',
            ], JSON_UNESCAPED_SLASHES) ?: '{"level":"error","message":"log record could not be encoded"}';
        }

        return $encoded;
    }
}
