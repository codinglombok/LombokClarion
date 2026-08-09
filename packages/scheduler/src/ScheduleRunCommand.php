<?php

declare(strict_types=1);

namespace LombokClarion\Scheduler;

use LombokClarion\Console\Command;

/**
 * CLI command: `schedule:run` — runs all due tasks.
 * Add to system crontab: `* * * * * php bin/lombokclarion schedule:run`
 *
 * `schedule:list` — lists all registered tasks with their expressions.
 */
final class ScheduleRunCommand implements Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
    }

    public function name(): string
    {
        return 'schedule:run';
    }

    public function description(): string
    {
        return 'Run due scheduled tasks.';
    }

    public function run(array $args): int
    {
        $results = $this->scheduler->run();

        if (empty($results)) {
            echo "No tasks due.\n";
            return 0;
        }

        foreach ($results as $result) {
            $status = match ($result['status']) {
                'ok' => "\033[32mOK\033[0m",
                'skipped' => "\033[33mSKIPPED\033[0m",
                'error' => "\033[31mERROR\033[0m",
            };

            $duration = round($result['duration'] * 1000, 1);
            echo "  [{$status}] {$result['task']} ({$duration}ms)";

            if (isset($result['error'])) {
                echo " — {$result['error']}";
            }

            echo "\n";
        }

        return 0;
    }
}
