<?php

declare(strict_types=1);

namespace LombokClarion\Notification;

use LombokClarion\Notification\Exceptions\NotificationException;

/**
 * Dispatches notifications through their declared channels.
 * Channels are registered explicitly — no discovery.
 *
 *   $dispatcher = new NotificationDispatcher();
 *   $dispatcher->registerChannel(new MailChannel($mailer));
 *   $dispatcher->registerChannel(new DatabaseChannel($pdo));
 *   $dispatcher->send($user, new InvoicePaid($invoice));
 */
final class NotificationDispatcher
{
    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    public function registerChannel(NotificationChannel $channel): void
    {
        $name = $channel->name();
        if (isset($this->channels[$name])) {
            throw new NotificationException("Notification channel '{$name}' already registered.");
        }
        $this->channels[$name] = $channel;
    }

    /**
     * Send a notification to one notifiable.
     */
    public function send(Notifiable $notifiable, Notification $notification): void
    {
        $via = $notification->via($notifiable);

        foreach ($via as $channelName) {
            if (!isset($this->channels[$channelName])) {
                throw new NotificationException(
                    "Notification channel '{$channelName}' is not registered. "
                    . 'Registered channels: ' . implode(', ', array_keys($this->channels))
                );
            }

            $this->channels[$channelName]->send($notifiable, $notification);
        }
    }

    /**
     * Send a notification to multiple notifiables.
     *
     * @param iterable<Notifiable> $notifiables
     */
    public function sendToMany(iterable $notifiables, Notification $notification): void
    {
        foreach ($notifiables as $notifiable) {
            $this->send($notifiable, $notification);
        }
    }
}
