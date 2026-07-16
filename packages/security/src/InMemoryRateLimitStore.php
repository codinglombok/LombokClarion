<?php

declare(strict_types=1);

namespace LombokClarion\Security;

/**
 * NOT safe for multi-process production deployments (FPM workers each get
 * their own copy, and it never survives across serverless invocations).
 * Bind RateLimitStore to a Redis/Memcached-backed implementation in
 * production instead.
 */
final class InMemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string, array{count: int, windowStart: int}> */
    private array $counters = [];

    public function increment(string $key, int $windowSeconds): int
    {
        $now = time();
        $entry = $this->counters[$key] ?? ['count' => 0, 'windowStart' => $now];

        if ($now - $entry['windowStart'] >= $windowSeconds) {
            $entry = ['count' => 0, 'windowStart' => $now];
        }

        $entry['count']++;
        $this->counters[$key] = $entry;

        return $entry['count'];
    }
}
