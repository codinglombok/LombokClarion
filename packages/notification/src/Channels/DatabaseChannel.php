<?php

declare(strict_types=1);

namespace LombokClarion\Notification\Channels;

use LombokClarion\Notification\Notifiable;
use LombokClarion\Notification\Notification;
use LombokClarion\Notification\NotificationChannel;
use LombokClarion\Persistence\Identifier;
use LombokClarion\Persistence\TrustedDdl;
use PDO;

/**
 * Stores notifications in a database table for in-app notification feeds.
 * The Notification subclass must implement toDatabase(Notifiable): array.
 */
final class DatabaseChannel implements NotificationChannel
{
    private bool $tableCreated = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'lc_notifications',
    ) {
        // The table name can never be a bound parameter, so it is validated
        // once here — every SQL build site below re-derives it through the
        // same validator the QueryBuilder uses.
        Identifier::validate($table);
    }

    public function name(): string
    {
        return 'database';
    }

    public function send(Notifiable $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toDatabase')) {
            throw new \BadMethodCallException(
                get_class($notification) . ' declares "database" channel but has no toDatabase() method.'
            );
        }

        $this->ensureTable();
        $table = Identifier::validate($this->table);
        $data = $notification->toDatabase($notifiable);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $table . ' (id, type, notifiable_id, data, read_at, created_at) '
            . 'VALUES (?, ?, ?, ?, NULL, ?)'
        );
        $stmt->execute([
            bin2hex(random_bytes(16)),
            get_class($notification),
            (string) $notifiable->notifiableId(),
            json_encode($data, JSON_THROW_ON_ERROR),
            date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array{id: string, type: string, data: array<mixed>, created_at: string}>
     */
    public function unread(Notifiable $notifiable, int $limit = 50): array
    {
        $this->ensureTable();
        $table = Identifier::validate($this->table);
        $stmt = $this->pdo->prepare(
            'SELECT id, type, data, created_at FROM ' . $table . ' '
            . 'WHERE notifiable_id = ? AND read_at IS NULL '
            . 'ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->execute([(string) $notifiable->notifiableId(), $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row) {
            $row['data'] = json_decode($row['data'], true);
            return $row;
        }, $rows);
    }

    public function markAsRead(string $notificationId): void
    {
        $this->ensureTable();
        $table = Identifier::validate($this->table);
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $table . ' SET read_at = ? WHERE id = ?'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $notificationId]);
    }

    public function markAllAsRead(Notifiable $notifiable): void
    {
        $this->ensureTable();
        $table = Identifier::validate($this->table);
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $table . ' SET read_at = ? WHERE notifiable_id = ? AND read_at IS NULL'
        );
        $stmt->execute([date('Y-m-d H:i:s'), (string) $notifiable->notifiableId()]);
    }

    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }
        $table = Identifier::validate($this->table);
        $this->pdo->exec(TrustedDdl::mark(
            'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
            . 'id VARCHAR(32) NOT NULL PRIMARY KEY, '
            . 'type VARCHAR(255) NOT NULL, '
            . 'notifiable_id VARCHAR(255) NOT NULL, '
            . 'data TEXT NOT NULL, '
            . 'read_at TEXT NULL, '
            . 'created_at TEXT NOT NULL'
            . ')'
        ));
        $this->tableCreated = true;
    }
}
