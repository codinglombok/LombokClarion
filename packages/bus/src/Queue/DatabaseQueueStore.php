<?php

declare(strict_types=1);

namespace LombokClarion\Bus\Queue;

use PDO;

/**
 * Persistent queue backed by a database table. For high-throughput queues
 * a dedicated store (Redis, SQS) is better — this exists so a working
 * queue is available out of the box without extra infrastructure, and
 * because it exercises the same QueryBuilder-style bound-parameters
 * discipline as the rest of the persistence layer.
 */
final class DatabaseQueueStore implements QueueStore
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->ensureTable();
    }

    public function push(QueuedJob $job): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO queued_jobs (id, queue, command_class, payload, attempts, max_attempts, backoff_seconds, available_at) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $job->id,
            $job->queue,
            $job->commandClass,
            $job->payload,
            $job->attempts,
            $job->maxAttempts,
            $job->backoffSeconds,
            $job->availableAt,
        ]);
    }

    public function pop(?string $queue = null): ?QueuedJob
    {
        $now = time();
        $sql = 'SELECT * FROM queued_jobs WHERE (available_at IS NULL OR available_at <= ?)';
        $params = [$now];

        if ($queue !== null) {
            $sql .= ' AND queue = ?';
            $params[] = $queue;
        }

        $sql .= ' ORDER BY rowid ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $del = $this->pdo->prepare('DELETE FROM queued_jobs WHERE id = ?');
        $del->execute([$row['id']]);

        return new QueuedJob(
            $row['id'],
            $row['queue'],
            $row['command_class'],
            $row['payload'],
            (int) $row['attempts'],
            (int) $row['max_attempts'],
            (int) $row['backoff_seconds'],
            $row['available_at'] !== null ? (int) $row['available_at'] : null,
        );
    }

    public function fail(QueuedJob $job, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO failed_jobs (id, queue, command_class, payload, error, failed_at) ' .
            'VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$job->id, $job->queue, $job->commandClass, $job->payload, $error, time()]);
    }

    public function size(?string $queue = null): int
    {
        $sql = 'SELECT COUNT(*) FROM queued_jobs';
        $params = [];

        if ($queue !== null) {
            $sql .= ' WHERE queue = ?';
            $params[] = $queue;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS queued_jobs (' .
            'id TEXT PRIMARY KEY, queue TEXT NOT NULL, command_class TEXT NOT NULL, ' .
            'payload TEXT NOT NULL, attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 1, ' .
            'backoff_seconds INTEGER DEFAULT 0, available_at INTEGER)'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS failed_jobs (' .
            'id TEXT PRIMARY KEY, queue TEXT NOT NULL, command_class TEXT NOT NULL, ' .
            'payload TEXT NOT NULL, error TEXT NOT NULL, failed_at INTEGER NOT NULL)'
        );
    }
}
