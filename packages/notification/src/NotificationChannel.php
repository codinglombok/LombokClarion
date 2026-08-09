<?php

declare(strict_types=1);

namespace LombokClarion\Notification;

/**
 * Contract for a notification delivery channel.
 * Each channel knows how to take a Notification and deliver it to one
 * Notifiable through its medium (email, database, SMS, Slack, etc.).
 */
interface NotificationChannel
{
    /**
     * The channel name as referenced in Notification::via().
     */
    public function name(): string;

    /**
     * Send the notification to the notifiable.
     */
    public function send(Notifiable $notifiable, Notification $notification): void;
}
