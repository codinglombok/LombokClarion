<?php

declare(strict_types=1);

namespace LombokClarion\Broadcasting\Drivers;

use LombokClarion\Broadcasting\Broadcaster;
use LombokClarion\Log\Logger;
use LombokClarion\Log\LogLevel;

/**
 * Logs broadcast events instead of delivering them. For development/testing.
 */
final class LogBroadcaster implements Broadcaster
{
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    public function broadcast(array $channels, string $event, array $payload): void
    {
        $this->logger->log(LogLevel::Info, 'Broadcast event', [
            'channels' => $channels,
            'event' => $event,
            'payload' => $payload,
        ]);
    }
}
