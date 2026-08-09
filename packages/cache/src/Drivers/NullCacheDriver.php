<?php

declare(strict_types=1);

namespace LombokClarion\Cache\Drivers;

use LombokClarion\Cache\Cache;

/**
 * Cache that caches nothing. Useful when you want to disable caching
 * for a specific store without changing any call sites.
 */
final class NullCacheDriver implements Cache
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function forget(string $key): void
    {
    }

    public function flush(): void
    {
    }

    public function remember(string $key, int $ttl, callable $factory): mixed
    {
        return $factory();
    }

    public function increment(string $key, int $amount = 1, int $ttl = 3600): int
    {
        return $amount;
    }

    public function decrement(string $key, int $amount = 1, int $ttl = 3600): int
    {
        return -$amount;
    }
}
