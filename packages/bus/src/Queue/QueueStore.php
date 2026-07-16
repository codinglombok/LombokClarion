<?php

declare(strict_types=1);

namespace LombokClarion\Bus\Queue;

/**
 * Abstraction over the queue backend (database, Redis, SQS, etc.).
 * Production apps bind a real implementation; tests and local dev use
 * InMemoryQueueStore or DatabaseQueueStore.
 */
interface QueueStore
{
    public function push(QueuedJob $job): void;

    /**
     * Dequeue the next available job, or null if the queue is empty.
     */
    public function pop(?string $queue = null): ?QueuedJob;

    /**
     * Mark a job as failed for diagnostic purposes.
     */
    public function fail(QueuedJob $job, string $error): void;

    /**
     * Number of pending jobs on the given queue.
     */
    public function size(?string $queue = null): int;
}
