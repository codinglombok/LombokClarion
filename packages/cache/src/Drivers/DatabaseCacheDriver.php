<?php

declare(strict_types=1);

namespace LombokClarion\Cache\Drivers;

use LombokClarion\Cache\Cache;
use LombokClarion\Persistence\Identifier;
use LombokClarion\Persistence\TrustedDdl;
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
        // The table name can never be a bound parameter, so it is validated
        // once here — every SQL build site below re-derives it through the
        // same validator the QueryBuilder uses.
        Identifier::validate($table);
    }

    public function get(string $key): mixed
    {
        $this->ensureTable();
        $table = Identifier::validate($this->table);

        $stmt = $this->pdo->prepare(
            'SELECT value, expiry FROM ' . $table . ' WHERE key = ?'
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
        $table = Identifier::validate($this->table);

        if ($driver === 'sqlite') {
            $sql = 'INSERT OR REPLACE INTO ' . $table . ' (key, value, expiry) VALUES (?, ?, ?)';
        } else {
            $sql = 'INSERT INTO ' . $table . ' (key, value, expiry) VALUES (?, ?, ?) '
                 . 'ON DUPLICATE KEY UPDATE value = VALUES(value), expiry = VALUES(expiry)';
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
        $table = Identifier::validate($this->table);

        $stmt = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE key = ?');
        $stmt->execute([$key]);
    }

    public function flush(): void
    {
        $this->ensureTable();
        $table = Identifier::validate($this->table);
        $this->pdo->exec('DELETE FROM ' . $table);
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
        $table = Identifier::validate($this->table);

        $stmt = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE expiry < ?');
        // PARAM_INT — SQLite orders any INTEGER below any TEXT, so a
        // string-bound time would invert this comparison and DELETE EVERY
        // row (live cache included). Numeric column, numeric parameter.
        $stmt->bindValue(1, time(), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        $table = Identifier::validate($this->table);

        $this->pdo->exec(TrustedDdl::mark(
            'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
            . 'key VARCHAR(255) NOT NULL PRIMARY KEY, '
            . 'value TEXT NOT NULL, '
            . 'expiry INTEGER NOT NULL'
            . ')'
        ));

        $this->tableCreated = true;
    }
}
