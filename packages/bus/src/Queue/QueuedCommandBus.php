<?php

declare(strict_types=1);

namespace LombokClarion\Bus\Queue;

use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\RetriesQueuedCommand;
use LombokClarion\Bus\RetryPolicy;

/**
 * Thin decorator around CommandBus that intercepts commands implementing
 * ShouldQueue: instead of running the handler inline, it serializes the
 * command and pushes it to the QueueStore. Commands that don't implement
 * ShouldQueue pass straight through to the real CommandBus.
 *
 * The worker (QueueWorker) then pops jobs and feeds them back into the
 * plain CommandBus, so the handler always runs the same code path
 * regardless of whether the command was dispatched inline or via the queue
 * — "queue/worker parity" (§12).
 */
final class QueuedCommandBus
{
    public function __construct(
        private readonly CommandBus $inner,
        private readonly QueueStore $store,
        private readonly string $defaultQueue = 'default',
    ) {
    }

    public function dispatch(object $command): mixed
    {
        if (!$command instanceof ShouldQueue) {
            return $this->inner->dispatch($command);
        }

        $retryPolicy = $command instanceof RetriesQueuedCommand
            ? $command->retryPolicy()
            : RetryPolicy::none();

        $job = new QueuedJob(
            id: bin2hex(random_bytes(16)),
            queue: $this->defaultQueue,
            commandClass: $command::class,
            payload: serialize($command),
            attempts: 0,
            maxAttempts: $retryPolicy->maxAttempts,
            backoffSeconds: $retryPolicy->backoffSeconds,
        );

        $this->store->push($job);

        return null; // Queued commands have no immediate return value.
    }
}
