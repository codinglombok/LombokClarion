<?php

declare(strict_types=1);

namespace LombokClarion\WebSocket;

use LombokClarion\WebSocket\Exceptions\WebSocketException;

/**
 * Tracks all active WebSocket connections. The runtime adapter registers
 * connections on open and removes them on close.
 */
final class ConnectionManager
{
    /** @var array<string, Connection> */
    private array $connections = [];

    public function add(Connection $connection): void
    {
        $this->connections[$connection->id] = $connection;
    }

    public function remove(string $connectionId): void
    {
        unset($this->connections[$connectionId]);
    }

    public function get(string $connectionId): Connection
    {
        return $this->connections[$connectionId]
            ?? throw new WebSocketException("Connection not found: {$connectionId}");
    }

    public function has(string $connectionId): bool
    {
        return isset($this->connections[$connectionId]);
    }

    /**
     * @return array<string, Connection>
     */
    public function all(): array
    {
        return $this->connections;
    }

    public function count(): int
    {
        return count($this->connections);
    }

    /**
     * Send a message to all connected clients.
     */
    public function broadcast(string $message, ?string $excludeConnectionId = null): void
    {
        foreach ($this->connections as $id => $connection) {
            if ($id !== $excludeConnectionId) {
                $connection->send($message);
            }
        }
    }

    /**
     * Find all connections belonging to a specific user.
     *
     * @return list<Connection>
     */
    public function forUser(string $userId): array
    {
        return array_values(
            array_filter($this->connections, fn (Connection $c) => $c->userId === $userId)
        );
    }
}
