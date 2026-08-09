<?php

declare(strict_types=1);

namespace LombokClarion\Scheduler;

use LombokClarion\Console\Command;

/**
 * CLI command: `schedule:list` — shows all registered scheduled tasks.
 */
final class ScheduleListCommand implements Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
    }

    public function name(): string
    {
        return 'schedule:list';
    }

    public function description(): string
    {
        return 'List all registered scheduled tasks.';
    }

    public function run(array $args): int
    {
        $tasks = $this->scheduler->list();

        if (empty($tasks)) {
            echo "No scheduled tasks registered.\n";
            return 0;
        }

        echo str_pad('Expression', 22) . str_pad('Due?', 6) . "Description\n";
        echo str_repeat('-', 70) . "\n";

        foreach ($tasks as $task) {
            $due = $task['next_due'] ? "\033[32mYES\033[0m " : ' no ';
            echo str_pad($task['expression'], 22) . $due . "  {$task['description']}\n";
        }

        return 0;
    }
}
