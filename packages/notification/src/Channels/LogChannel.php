<?php

declare(strict_types=1);

namespace LombokClarion\Notification\Channels;

use LombokClarion\Log\Logger;
use LombokClarion\Log\LogLevel;
use LombokClarion\Notification\Notifiable;
use LombokClarion\Notification\Notification;
use LombokClarion\Notification\NotificationChannel;

/**
 * Logs notifications instead of delivering them. Use for dev/testing.
 */
final class LogChannel implements NotificationChannel
{
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    public function name(): string
    {
        return 'log';
    }

    public function send(Notifiable $notifiable, Notification $notification): void
    {
        $this->logger->log(LogLevel::Info, 'Notification dispatched', [
            'notification' => get_class($notification),
            'notifiable_id' => $notifiable->notifiableId(),
            'channels' => $notification->via($notifiable),
        ]);
    }
}
