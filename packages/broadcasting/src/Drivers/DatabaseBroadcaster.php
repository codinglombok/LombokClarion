<?php

declare(strict_types=1);

namespace LombokClarion\Broadcasting\Drivers;

use LombokClarion\Broadcasting\Broadcaster;
use PDO;

final class DatabaseBroadcaster implements Broadcaster
{
    private bool $tableCreated = false;
    private string $quotedTable;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'lc_broadcasts',
    ) {
        // Quote the table name to safely handle it in SQL
        $this->quotedTable = '`' . str_replace('`', '``', $this->table) . '`';
    }

    public function broadcast(array $channels, string $event, array $payload): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->quotedTable} (id, channel, event, payload, created_at) VALUES (?, ?, ?, ?, ?)"
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

    public function since(string $channel, ?string $afterId = null, int $limit = 100): array
    {
        $this->ensureTable();

        if ($afterId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT id, event, payload, created_at FROM {$this->quotedTable} "
                . "WHERE channel = ? AND rowid > (SELECT rowid FROM {$this->quotedTable} WHERE id = ?) "
                . "ORDER BY rowid ASC LIMIT ?"
            );
            $stmt->execute([$channel, $afterId, $limit]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT id, event, payload, created_at FROM {$this->quotedTable} "
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

    public function gc(int $seconds = 86400): int
    {
        $this->ensureTable();
        $threshold = date('Y-m-d H:i:s', time() - $seconds);
    //    $stmt = $this->pdo->prepare("DELETE FROM {$this->quotedTable} WHERE created_at < ?");
     //   $stmt->execute([$threshold]);
        // Line 76 - FIXED
$sql = "DELETE FROM " . $this->quotedTable . " WHERE created_at < ?";
$stmt = $this->pdo->prepare($sql);
$stmt->execute([$threshold]);
        return $stmt->rowCount();
    }

    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }
      //  $this->pdo->exec(
     //       "CREATE TABLE IF NOT EXISTS {$this->quotedTable} ("
     //       . "id VARCHAR(32) NOT NULL PRIMARY KEY, "
     //       . "channel VARCHAR(255) NOT NULL, "
     //       . "event VARCHAR(255) NOT NULL, "
    //        . "payload TEXT NOT NULL, "
     //       . "created_at TEXT NOT NULL"
     //       . ")"
     //   );
        // Line 86 - FIXED
     $sql = "CREATE TABLE IF NOT EXISTS " . $this->quotedTable . " ("
    . "id VARCHAR(32) NOT NULL PRIMARY KEY, "
    . "channel VARCHAR(255) NOT NULL, "
    . "event VARCHAR(255) NOT NULL, "
    . "payload TEXT NOT NULL, "
    . "created_at TEXT NOT NULL"
    . ")";
$this->pdo->exec($sql);
        $this->tableCreated = true;
    }
}



