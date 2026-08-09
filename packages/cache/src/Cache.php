<?php

declare(strict_types=1);

namespace LombokClarion\Cache;

/**
 * Cache contract. Drivers implement this; application code depends only on
 * this interface, never on a specific driver.
 *
 * TTL is always explicit seconds — no magic "forever" default. If you want
 * something cached indefinitely, pass a large TTL and accept that restarts
 * clear it (array) or disk fills (file). The only cache that survives a
 * deploy is the database driver, and even that has an explicit expiry column.
 *
 * Null returns mean "not in cache" — a cache NEVER stores null as a value.
 * Use has() + get() if you need to distinguish "cached null" from "miss",
 * but the framework convention is: don't cache null.
 */
interface Cache
{
    /**
     * Retrieve a value, or null on miss / expiry.
     */
    public function get(string $key): mixed;

    /**
     * Store a value for $ttl seconds. Overwrites silently if the key exists.
     *
     * @param positive-int $ttl seconds until expiry
     */
    public function put(string $key, mixed $value, int $ttl): void;

    /**
     * True when the key exists AND has not expired.
     */
    public function has(string $key): bool;

    /**
     * Remove a key. No-op if absent.
     */
    public function forget(string $key): void;

    /**
     * Remove every key this driver owns. Drivers that share storage with
     * non-cache data (database) scope the flush to their own table/prefix.
     */
    public function flush(): void;

    /**
     * Retrieve a value, or call $factory, store its return for $ttl, and
     * return it. The factory is NOT called when the cache has a valid entry.
     *
     * @param positive-int $ttl
     */
    public function remember(string $key, int $ttl, callable $factory): mixed;

    /**
     * Atomically increment a numeric value. Returns the new value.
     * Creates the key with value $amount if it does not exist.
     *
     * @param positive-int $ttl only used when creating a new key
     */
    public function increment(string $key, int $amount = 1, int $ttl = 3600): int;

    /**
     * Atomically decrement a numeric value. Returns the new value.
     *
     * @param positive-int $ttl only used when creating a new key
     */
    public function decrement(string $key, int $amount = 1, int $ttl = 3600): int;
}
