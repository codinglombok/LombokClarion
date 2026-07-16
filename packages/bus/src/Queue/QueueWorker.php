<?php

declare(strict_types=1);

namespace LombokClarion\Bus\Queue;

use LombokClarion\Bus\CommandBus;
use Throwable;

/**
 * The worker process that actually handles queued commands. It runs the
 * same CommandBus::dispatch() that inline dispatch uses — the handler
 * code never knows or cares whether the command arrived synchronously or
 * from a queue (§12: parity).
 *
 * Default: single-attempt, no retry (§10). Commands that opt in via
 * RetriesQueuedCommand get their declared maxAttempts/backoff honoured.
 * When all attempts are exhausted, the job goes to the failed-jobs store.
 */
final class QueueWorker
{
    public function __construct(
        private readonly CommandBus $bus,
        private readonly QueueStore $store,
    ) {
    }

    /**
     * Process a single job. Returns true if a job was processed, false if
     * the queue was empty.
     */
    public function processNext(?string $queue = null): bool
    {
        $job = $this->store->pop($queue);

        if ($job === null) {
            return false;
        }

        $job = $job->withAttemptIncremented();

        try {
            /** @var object $command */
            $command = unserialize($job->payload);
            $this->bus->dispatch($command);

            return true;
        } catch (Throwable $e) {
            if ($job->attempts < $job->maxAttempts) {
                // Re-enqueue with backoff.
                $retryJob = new QueuedJob(
                    $job->id,
                    $job->queue,
                    $job->commandClass,
                    $job->payload,
                    $job->attempts,
                    $job->maxAttempts,
                    $job->backoffSeconds,
                    $job->backoffSeconds > 0 ? time() + ($job->backoffSeconds * $job->attempts) : null,
                );
                $this->store->push($retryJob);
            } else {
                $this->store->fail($job, $e->getMessage());
            }

            return true;
        }
    }

    /**
     * Run the worker loop: process all available jobs, return count.
     * Intentionally NOT an infinite loop — callers (CLI command, Swoole
     * timer callback, Lambda handler) decide loop/sleep/exit policy.
     */
    public function drain(?string $queue = null, int $maxJobs = 100): int
    {
        $processed = 0;

        while ($processed < $maxJobs && $this->processNext($queue)) {
            $processed++;
        }

        return $processed;
    }
}
