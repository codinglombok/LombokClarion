<?php

declare(strict_types=1);

namespace LombokClarion\Broadcasting;

/**
 * Marker interface for events that should be broadcast to frontend clients.
 * Implement this on your event class and define broadcastOn() + broadcastAs().
 */
interface ShouldBroadcast
{
    /**
     * The channel(s) to broadcast on.
     *
     * @return list<string|Channel> channel names or Channel objects
     */
    public function broadcastOn(): array;

    /**
     * The event name the client listens for (e.g. 'OrderShipped').
     */
    public function broadcastAs(): string;

    /**
     * The payload to send. Must be JSON-serializable.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array;
}
