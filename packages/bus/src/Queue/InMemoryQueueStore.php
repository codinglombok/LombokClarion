<?php

declare(strict_types=1);

namespace LombokClarion\Bus\Queue;

final class InMemoryQueueStore implements QueueStore
{
    /** @var list<QueuedJob> */
    private array $jobs = [];

    /** @var list<array{job: QueuedJob, error: string}> */
    private array $failed = [];

    public function push(QueuedJob $job): void
    {
        $this->jobs[] = $job;
    }

    public function pop(?string $queue = null): ?QueuedJob
    {
        $now = time();

        foreach ($this->jobs as $i => $job) {
            if ($queue !== null && $job->queue !== $queue) {
                continue;
            }
            if ($job->availableAt !== null && $job->availableAt > $now) {
                continue;
            }

            array_splice($this->jobs, $i, 1);
            return $job;
        }

        return null;
    }

    public function fail(QueuedJob $job, string $error): void
    {
        $this->failed[] = ['job' => $job, 'error' => $error];
    }

    public function size(?string $queue = null): int
    {
        if ($queue === null) {
            return count($this->jobs);
        }

        return count(array_filter($this->jobs, fn (QueuedJob $j) => $j->queue === $queue));
    }

    /** @return list<array{job: QueuedJob, error: string}> */
    public function failedJobs(): array
    {
        return $this->failed;
    }
}
