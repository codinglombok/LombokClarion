<?php

declare(strict_types=1);

namespace LombokClarion\Broadcasting\Drivers;

use LombokClarion\Broadcasting\Broadcaster;

/**
 * No-op broadcaster. Use when broadcasting is not needed.
 */
final class NullBroadcaster implements Broadcaster
{
    public function broadcast(array $channels, string $event, array $payload): void
    {
    }
}
