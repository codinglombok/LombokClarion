<?php

declare(strict_types=1);

namespace LombokClarion\Bus\Queue;

final class QueuedJob
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $commandClass,
        public readonly string $payload,
        public readonly int $attempts = 0,
        public readonly int $maxAttempts = 1,
        public readonly int $backoffSeconds = 0,
        public readonly ?int $availableAt = null,
    ) {
    }

    public function withAttemptIncremented(): self
    {
        return new self(
            $this->id,
            $this->queue,
            $this->commandClass,
            $this->payload,
            $this->attempts + 1,
            $this->maxAttempts,
            $this->backoffSeconds,
            null,
        );
    }

    public function withBackoff(): self
    {
        $delay = $this->backoffSeconds * ($this->attempts + 1);
        return new self(
            $this->id,
            $this->queue,
            $this->commandClass,
            $this->payload,
            $this->attempts,
            $this->maxAttempts,
            $this->backoffSeconds,
            time() + $delay,
        );
    }
}
