<?php

declare(strict_types=1);

namespace LombokClarion\Log;

/**
 * A destination for records: a file, syslog, a table, a test buffer.
 *
 * Handlers own their own minimum level rather than being filtered by the
 * logger, because the useful configuration is per-destination — debug to the
 * local file, warnings and up to syslog, criticals to the alerting channel.
 * A single logger-wide threshold cannot express that, and every deployment
 * eventually needs it.
 */
interface LogHandler
{
    /**
     * Write the record if this handler wants it. Deciding whether to write is
     * the handler's job, not the caller's — see {@see LogLevel::isAtLeast()}.
     */
    public function handle(LogRecord $record): void;
}
