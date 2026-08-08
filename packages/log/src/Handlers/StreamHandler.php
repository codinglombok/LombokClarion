<?php

declare(strict_types=1);

namespace LombokClarion\Log\Handlers;

use LombokClarion\Log\Formatters\JsonFormatter;
use LombokClarion\Log\LogFormatter;
use LombokClarion\Log\LogHandler;
use LombokClarion\Log\LogLevel;
use LombokClarion\Log\LogRecord;
use RuntimeException;

/**
 * Appends formatted records to a file, one per line, with optional daily
 * rotation and retention.
 *
 * ROTATION IS BY DATE IN THE FILENAME, not by size. Size-based rotation has to
 * rename files while other processes hold them open — under FPM that is
 * several processes, and the classic result is entries written into a file
 * that has already been renamed out from under the writer. Date-suffixed names
 * mean every process independently computes the same filename for the same
 * day and nothing ever gets renamed. The cost is that a single day can produce
 * a large file; that is a retention problem, not a correctness one.
 *
 * WRITE FAILURES DO NOT THROW. A logger that raises when the disk is full
 * turns a degraded service into a dead one, and it does it inside the error
 * path — where the exception has nowhere useful to go and is most likely to
 * replace the message you actually needed. Failures are silent by design;
 * {@see ChannelLogger} isolates handlers from each other for the same reason.
 * The one thing that IS loud is a directory that cannot be created at
 * construction time, because that is a misconfiguration visible at boot rather
 * than at 3am.
 */
final class StreamHandler implements LogHandler
{
    private readonly LogFormatter $formatter;

    /**
     * @param string $path file path. With $daily, the date is inserted before
     *        the extension: /var/log/app.log => /var/log/app-2026-08-07.log
     * @param int $retentionDays with $daily, delete rotated files older than
     *        this many days. 0 disables cleanup entirely.
     */
    public function __construct(
        private readonly string $path,
        private readonly LogLevel $minimumLevel = LogLevel::Debug,
        ?LogFormatter $formatter = null,
        private readonly bool $daily = false,
        private readonly int $retentionDays = 0,
    ) {
        $this->formatter = $formatter ?? new JsonFormatter();

        $directory = dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException("Log directory does not exist and could not be created: $directory");
        }
    }

    public function handle(LogRecord $record): void
    {
        if (!$record->level->isAtLeast($this->minimumLevel)) {
            return;
        }

        $file = $this->resolvePath($record);

        // FILE_APPEND + LOCK_EX: concurrent FPM workers append whole lines
        // without interleaving halfway through one. Without the lock, two
        // workers writing at once produce a spliced line that no parser
        // recovers from.
        @file_put_contents($file, $this->formatter->format($record) . "\n", FILE_APPEND | LOCK_EX);

        $this->pruneIfDue();
    }

    private function resolvePath(LogRecord $record): string
    {
        if (!$this->daily) {
            return $this->path;
        }

        $extension = pathinfo($this->path, PATHINFO_EXTENSION);
        $stem = $extension === ''
            ? $this->path
            : substr($this->path, 0, -(strlen($extension) + 1));

        $date = $record->time()->format('Y-m-d');

        return $extension === '' ? "$stem-$date" : "$stem-$date.$extension";
    }

    /**
     * Deletes rotated files whose date suffix is older than the retention
     * window. Runs at most once per process: doing a directory scan on every
     * single log write would make logging cost O(files) per line.
     */
    private function pruneIfDue(): void
    {
        static $pruned = [];

        if (!$this->daily || $this->retentionDays <= 0) {
            return;
        }

        if (isset($pruned[$this->path])) {
            return;
        }

        $pruned[$this->path] = true;

        $extension = pathinfo($this->path, PATHINFO_EXTENSION);
        $stem = $extension === ''
            ? $this->path
            : substr($this->path, 0, -(strlen($extension) + 1));

        $pattern = $extension === '' ? "$stem-*" : "$stem-*.$extension";
        $cutoff = time() - ($this->retentionDays * 86400);

        foreach (glob($pattern) ?: [] as $candidate) {
            // Match the date out of the name rather than trusting mtime: a
            // file touched by a backup tool would otherwise look young forever.
            if (preg_match('/-(\d{4}-\d{2}-\d{2})(?:\.[^.]+)?$/', $candidate, $matches) !== 1) {
                continue;
            }

            $fileTime = strtotime($matches[1]);

            if ($fileTime !== false && $fileTime < $cutoff) {
                @unlink($candidate);
            }
        }
    }
}
