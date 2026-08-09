<?php

declare(strict_types=1);

namespace LombokClarion\Session\Handlers;

use LombokClarion\Session\SessionHandler;
use PDO;

/**
 * Database-backed session handler. Stores session data in a single table.
 */
final class DatabaseSessionHandler implements SessionHandler
{
    private bool $tableCreated = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'lc_sessions',
    ) {
    }

    public function read(string $id): ?string
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare(
            "SELECT payload FROM {$this->table} WHERE id = ? AND last_activity + lifetime > ?"
        );
        $stmt->execute([$id, time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row['payload'] : null;
    }

    public function write(string $id, string $data, int $lifetime): void
    {
        $this->ensureTable();
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = "INSERT OR REPLACE INTO {$this->table} (id, payload, last_activity, lifetime) VALUES (?, ?, ?, ?)";
        } else {
            $sql = "INSERT INTO {$this->table} (id, payload, last_activity, lifetime) VALUES (?, ?, ?, ?) "
                 . "ON DUPLICATE KEY UPDATE payload = VALUES(payload), last_activity = VALUES(last_activity), lifetime = VALUES(lifetime)";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id, $data, time(), $lifetime]);
    }

    public function destroy(string $id): void
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function gc(int $maxLifetime): int
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE last_activity + lifetime < ?");
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
            . "id VARCHAR(64) NOT NULL PRIMARY KEY, "
            . "payload TEXT NOT NULL, "
            . "last_activity INTEGER NOT NULL, "
            . "lifetime INTEGER NOT NULL"
            . ")"
        );
        $this->tableCreated = true;
    }
}
