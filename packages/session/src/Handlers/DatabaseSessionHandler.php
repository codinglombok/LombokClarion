<?php

declare(strict_types=1);

namespace LombokClarion\Session\Handlers;

use LombokClarion\Persistence\Identifier;
use LombokClarion\Persistence\TrustedDdl;
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
        // The table name can never be a bound parameter, so it is validated
        // once here — every SQL build site below re-derives it through the
        // same validator the QueryBuilder uses.
        Identifier::validate($table);
    }

    public function read(string $id): ?string
    {
        $this->ensureTable();
        $table = Identifier::validate($this->table);
        $stmt = $this->pdo->prepare(
            'SELECT payload FROM ' . $table . ' WHERE id = ? AND last_activity + lifetime > ?'
        );
        // time() MUST bind as PDO::PARAM_INT: executed-array values bind as
        // strings, and in SQLite any INTEGER < any TEXT — an int-vs-string
        // comparison silently inverts (reads always miss, gc deletes live
        // sessions). The numeric columns below compare against a numeric param.
        $stmt->bindValue(1, $id, PDO::PARAM_STR);
        $stmt->bindValue(2, time(), PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row['payload'] : null;
    }

    public function write(string $id, string $data, int $lifetime): void
    {
        $this->ensureTable();
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $table = Identifier::validate($this->table);

        if ($driver === 'sqlite') {
            $sql = 'INSERT OR REPLACE INTO ' . $table . ' (id, payload, last_activity, lifetime) VALUES (?, ?, ?, ?)';
        } else {
            $sql = 'INSERT INTO ' . $table . ' (id, payload, last_activity, lifetime) VALUES (?, ?, ?, ?) '
                 . 'ON DUPLICATE KEY UPDATE payload = VALUES(payload), last_activity = VALUES(last_activity), lifetime = VALUES(lifetime)';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id, $data, time(), $lifetime]);
    }

    public function destroy(string $id): void
    {
        $this->ensureTable();
        $table = Identifier::validate($this->table);
        $stmt = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function gc(int $maxLifetime): int
    {
        $this->ensureTable();
        $table = Identifier::validate($this->table);
        $stmt = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE last_activity + lifetime < ?');
        // PARAM_INT — see the type-affinity note in read().
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
            . 'id VARCHAR(64) NOT NULL PRIMARY KEY, '
            . 'payload TEXT NOT NULL, '
            . 'last_activity INTEGER NOT NULL, '
            . 'lifetime INTEGER NOT NULL'
            . ')'
        ));
        $this->tableCreated = true;
    }
}
