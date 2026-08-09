<?php

declare(strict_types=1);

namespace LombokClarion\Broadcasting;

/**
 * Dispatches events that implement ShouldBroadcast to the configured
 * broadcaster backend.
 *
 * Typically wired as an EventBus listener: when an event fires, if it
 * implements ShouldBroadcast, this manager pushes it to all declared channels.
 */
final class BroadcastManager
{
    public function __construct(
        private readonly Broadcaster $broadcaster,
    ) {
    }

    /**
     * Broadcast an event if it implements ShouldBroadcast.
     */
    public function dispatch(object $event): void
    {
        if (!$event instanceof ShouldBroadcast) {
            return;
        }

        $channels = array_map(
            fn (string|Channel $ch) => $ch instanceof Channel ? $ch->name : $ch,
            $event->broadcastOn()
        );

        $this->broadcaster->broadcast(
            $channels,
            $event->broadcastAs(),
            $event->broadcastWith(),
        );
    }
}
