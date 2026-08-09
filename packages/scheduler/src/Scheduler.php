<?php

declare(strict_types=1);

namespace LombokClarion\Scheduler;

use LombokClarion\Console\ConsoleKernel;
use LombokClarion\Log\Logger;
use LombokClarion\Log\LogLevel;

/**
 * The scheduler. Register tasks explicitly, then call run() from a
 * single system cron entry: `* * * * * php bin/lombokclarion schedule:run`
 *
 * No auto-discovery: every task is declared in bootstrap/schedule.php.
 */
final class Scheduler
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    /** @var array<string, bool> overlap locks (in-memory, per-process) */
    private array $locks = [];

    public function __construct(
        private readonly ConsoleKernel $console,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function add(ScheduledTask $task): self
    {
        $this->tasks[] = $task;
        return $this;
    }

    /**
     * @return list<ScheduledTask>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * Run all due tasks. Called once per minute by the system cron.
     *
     * @return list<array{task: string, status: 'ok'|'skipped'|'error', duration: float, error?: string}>
     */
    public function run(?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $results = [];

        foreach ($this->tasks as $task) {
            if (!$task->isDueAt($now)) {
                continue;
            }

            $desc = $task->description();

            if ($task->preventsOverlapping() && isset($this->locks[$desc])) {
                $results[] = ['task' => $desc, 'status' => 'skipped', 'duration' => 0.0];
                $this->log(LogLevel::Info, "Scheduler: skipped (overlapping) {$desc}");
                continue;
            }

            $start = microtime(true);
            $this->locks[$desc] = true;

            try {
                if ($task->isCommand() && $task->commandName() !== null) {
                    $args = array_merge([$task->commandName()], $task->commandArgs());
                    $this->console->handle($args);
                } elseif ($task->callback() !== null) {
                    ($task->callback())();
                }

                $duration = microtime(true) - $start;
                $results[] = ['task' => $desc, 'status' => 'ok', 'duration' => $duration];
                $this->log(LogLevel::Info, "Scheduler: completed {$desc}", ['duration_ms' => round($duration * 1000, 2)]);
            } catch (\Throwable $e) {
                $duration = microtime(true) - $start;
                $results[] = ['task' => $desc, 'status' => 'error', 'duration' => $duration, 'error' => $e->getMessage()];
                $this->log(LogLevel::Error, "Scheduler: failed {$desc}", ['error' => $e->getMessage()]);
            } finally {
                unset($this->locks[$desc]);
            }
        }

        return $results;
    }

    /**
     * List all tasks with their cron expressions.
     *
     * @return list<array{description: string, expression: string, next_due: bool}>
     */
    public function list(): array
    {
        $now = new \DateTimeImmutable();

        return array_map(fn (ScheduledTask $t) => [
            'description' => $t->description(),
            'expression' => $t->expression(),
            'next_due' => $t->isDueAt($now),
        ], $this->tasks);
    }

    private function log(LogLevel $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, $message, $context);
    }
}
