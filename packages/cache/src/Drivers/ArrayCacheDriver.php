<?php

declare(strict_types=1);

namespace LombokClarion\Cache\Drivers;

use LombokClarion\Cache\Cache;

/**
 * In-memory (per-process) cache. Lives only for the current request
 * under FPM, or until the worker restarts under Swoole.
 *
 * Primary use case: testing. Bind this to Cache in your test harness
 * to avoid file/DB side effects.
 */
final class ArrayCacheDriver implements Cache
{
    /** @var array<string, array{value: mixed, expiry: int}> */
    private array $store = [];

    public function get(string $key): mixed
    {
        if (!isset($this->store[$key])) {
            return null;
        }

        if ($this->store[$key]['expiry'] < time()) {
            unset($this->store[$key]);
            return null;
        }

        return $this->store[$key]['value'];
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $this->store[$key] = [
            'value' => $value,
            'expiry' => time() + $ttl,
        ];
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }

    public function flush(): void
    {
        $this->store = [];
    }

    public function remember(string $key, int $ttl, callable $factory): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $factory();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function increment(string $key, int $amount = 1, int $ttl = 3600): int
    {
        $current = $this->get($key);
        $new = ($current !== null ? (int) $current : 0) + $amount;
        $this->put($key, $new, $ttl);

        return $new;
    }

    public function decrement(string $key, int $amount = 1, int $ttl = 3600): int
    {
        return $this->increment($key, -$amount, $ttl);
    }

    /**
     * @return array<string, mixed> snapshot for test assertions
     */
    public function all(): array
    {
        // Purge expired
        $now = time();
        $this->store = array_filter(
            $this->store,
            fn (array $entry) => $entry['expiry'] >= $now
        );

        return array_map(fn (array $entry) => $entry['value'], $this->store);
    }
}
