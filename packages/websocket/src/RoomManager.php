<?php

declare(strict_types=1);

namespace LombokClarion\WebSocket;

/**
 * Room-based pub/sub for WebSocket connections. A connection can join
 * multiple rooms; messages sent to a room reach all members.
 */
final class RoomManager
{
    /** @var array<string, array<string, Connection>> room → [connId → Connection] */
    private array $rooms = [];

    /** @var array<string, list<string>> connId → [room names] */
    private array $connectionRooms = [];

    public function join(Connection $connection, string $room): void
    {
        $this->rooms[$room][$connection->id] = $connection;
        $this->connectionRooms[$connection->id][] = $room;
    }

    public function leave(Connection $connection, string $room): void
    {
        unset($this->rooms[$room][$connection->id]);

        if (empty($this->rooms[$room])) {
            unset($this->rooms[$room]);
        }

        if (isset($this->connectionRooms[$connection->id])) {
            $this->connectionRooms[$connection->id] = array_values(
                array_filter(
                    $this->connectionRooms[$connection->id],
                    fn (string $r) => $r !== $room
                )
            );
        }
    }

    /**
     * Remove a connection from ALL rooms. Called on disconnect.
     */
    public function leaveAll(Connection $connection): void
    {
        foreach ($this->connectionRooms[$connection->id] ?? [] as $room) {
            unset($this->rooms[$room][$connection->id]);
            if (empty($this->rooms[$room])) {
                unset($this->rooms[$room]);
            }
        }

        unset($this->connectionRooms[$connection->id]);
    }

    /**
     * Send a message to all connections in a room, optionally excluding one.
     */
    public function broadcast(string $room, string $message, ?string $excludeConnectionId = null): void
    {
        foreach ($this->rooms[$room] ?? [] as $connId => $connection) {
            if ($connId !== $excludeConnectionId) {
                $connection->send($message);
            }
        }
    }

    /**
     * Send JSON to all connections in a room.
     */
    public function broadcastJson(string $room, mixed $data, ?string $excludeConnectionId = null): void
    {
        $this->broadcast(
            $room,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $excludeConnectionId,
        );
    }

    /**
     * @return list<string>
     */
    public function rooms(): array
    {
        return array_keys($this->rooms);
    }

    public function roomSize(string $room): int
    {
        return count($this->rooms[$room] ?? []);
    }

    /**
     * @return list<Connection>
     */
    public function members(string $room): array
    {
        return array_values($this->rooms[$room] ?? []);
    }
}
