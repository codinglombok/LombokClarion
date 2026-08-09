<?php

declare(strict_types=1);

namespace LombokClarion\Broadcasting;

/**
 * Backend for broadcasting events. Implementations: DatabaseBroadcaster,
 * LogBroadcaster, NullBroadcaster. A Redis broadcaster is one class away.
 */
interface Broadcaster
{
    /**
     * Broadcast an event payload to the given channels.
     *
     * @param list<string> $channels
     * @param array<string, mixed> $payload
     */
    public function broadcast(array $channels, string $event, array $payload): void;
}
