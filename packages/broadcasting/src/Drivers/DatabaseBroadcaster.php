<?php

declare(strict_types=1);

namespace LombokClarion\Broadcasting\Drivers;

use LombokClarion\Broadcasting\Broadcaster;
use PDO;

/**
 * Stores broadcast events in a database table. Clients poll or use SSE
 * to read new events. Suitable for moderate traffic without Redis.
 */
final class DatabaseBroadcaster implements Broadcaster
{
    private bool $tableCreated = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'lc_broadcasts',
    ) {
    }

    public function broadcast(array $channels, string $event, array $payload): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (id, channel, event, payload, created_at) VALUES (?, ?, ?, ?, ?)"
        );

        $now = date('Y-m-d H:i:s');
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach ($channels as $channel) {
            $stmt->execute([
                bin2hex(random_bytes(16)),
                $channel,
                $event,
                $json,
                $now,
            ]);
        }
    }

    /**
     * Fetch broadcast events for a channel since a given ID.
     *
     * @return list<array{id: string, event: string, payload: array<mixed>, created_at: string}>
     */
    public function since(string $channel, ?string $afterId = null, int $limit = 100): array
    {
        $this->ensureTable();

        if ($afterId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT id, event, payload, created_at FROM {$this->table} "
                . "WHERE channel = ? AND rowid > (SELECT rowid FROM {$this->table} WHERE id = ?) "
                . "ORDER BY rowid ASC LIMIT ?"
            );
            $stmt->execute([$channel, $afterId, $limit]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT id, event, payload, created_at FROM {$this->table} "
                . "WHERE channel = ? ORDER BY rowid DESC LIMIT ?"
            );
            $stmt->execute([$channel, $limit]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row) {
            $row['payload'] = json_decode($row['payload'], true);
            return $row;
        }, $rows);
    }

    /**
     * Remove events older than $seconds.
     */
    public function gc(int $seconds = 86400): int
    {
        $this->ensureTable();
        $threshold = date('Y-m-d H:i:s', time() - $seconds);
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE created_at < ?");
        $stmt->execute([$threshold]);
        return $stmt->rowCount();
    }

    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} ("
            . "id VARCHAR(32) NOT NULL PRIMARY KEY, "
            . "channel VARCHAR(255) NOT NULL, "
            . "event VARCHAR(255) NOT NULL, "
            . "payload TEXT NOT NULL, "
            . "created_at TEXT NOT NULL"
            . ")"
        );
        $this->tableCreated = true;
    }
}
