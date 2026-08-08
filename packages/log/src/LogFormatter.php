<?php

declare(strict_types=1);

namespace LombokClarion\Log;

/**
 * Turns a record into the bytes a handler writes. Separate from the handler so
 * that "where it goes" and "what it looks like" compose: JSON to file, lines
 * to stderr, the same records either way.
 */
interface LogFormatter
{
    /**
     * The returned string must NOT end in a newline — line termination belongs
     * to the handler, which is the only thing that knows whether its
     * destination is line-oriented at all.
     */
    public function format(LogRecord $record): string;
}
