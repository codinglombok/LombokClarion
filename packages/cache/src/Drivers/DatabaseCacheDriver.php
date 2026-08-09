<?php

declare(strict_types=1);

namespace LombokClarion\Cache\Drivers;

use LombokClarion\Cache\Cache;
use PDO;

/**
 * Database-backed cache driver. Stores serialized values in a single table
 * with an explicit expiry column. The table is created automatically on
 * first write (idempotent CREATE IF NOT EXISTS).
 *
 * This driver survives deploys and process restarts, but is slower than
 * file or in-memory caches. Use it when durability matters more than
 * latency, or as a fallback when Redis/Memcached are not available.
 */
final class DatabaseCacheDriver implements Cache
{
    private bool $tableCreated = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'lc_cache',
    ) {
    }

    public function get(string $key): mixed
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "SELECT value, expiry FROM {$this->table} WHERE key = ?"
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if ((int) $row['expiry'] < time()) {
            $this->forget($key);
            return null;
        }

        return unserialize($row['value']);
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $this->ensureTable();

        $expiry = time() + $ttl;
        $serialized = serialize($value);

        // UPSERT: SQLite uses INSERT OR REPLACE, MySQL uses ON DUPLICATE KEY
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = "INSERT OR REPLACE INTO {$this->table} (key, value, expiry) VALUES (?, ?, ?)";
        } else {
            $sql = "INSERT INTO {$this->table} (key, value, expiry) VALUES (?, ?, ?) "
                 . "ON DUPLICATE KEY UPDATE value = VALUES(value), expiry = VALUES(expiry)";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$key, $serialized, $expiry]);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function forget(string $key): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE key = ?");
        $stmt->execute([$key]);
    }

    public function flush(): void
    {
        $this->ensureTable();
        $this->pdo->exec("DELETE FROM {$this->table}");
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
     * Remove all expired rows. Call from a scheduled task if the table grows.
     */
    public function gc(): int
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expiry < ?");
        $stmt->execute([time()]);

        return $stmt->rowCount();
    }

    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} ("
            . 'key VARCHAR(255) NOT NULL PRIMARY KEY, '
            . 'value TEXT NOT NULL, '
            . 'expiry INTEGER NOT NULL'
            . ')'
        );

        $this->tableCreated = true;
    }
}
