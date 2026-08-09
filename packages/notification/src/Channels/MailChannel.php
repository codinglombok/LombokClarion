<?php

declare(strict_types=1);

namespace LombokClarion\Notification\Channels;

use LombokClarion\Mail\Content;
use LombokClarion\Mail\Envelope;
use LombokClarion\Mail\Mailable;
use LombokClarion\Mail\Mailer;
use LombokClarion\Notification\Notifiable;
use LombokClarion\Notification\Notification;
use LombokClarion\Notification\NotificationChannel;

/**
 * Sends notifications via email. The Notification must implement
 * toMail(Notifiable): Mailable.
 *
 * Requires lombokclarion/mail.
 */
final class MailChannel implements NotificationChannel
{
    public function __construct(
        private readonly Mailer $mailer,
    ) {
    }

    public function name(): string
    {
        return 'mail';
    }

    public function send(Notifiable $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toMail')) {
            throw new \BadMethodCallException(
                get_class($notification) . ' declares "mail" channel but has no toMail() method.'
            );
        }

        $mailable = $notification->toMail($notifiable);

        // If envelope has no recipient, route from the notifiable
        $envelope = $mailable->envelope();
        if (empty($envelope->to)) {
            $email = $notifiable->routeNotificationFor('mail');
            if (is_string($email)) {
                // Wrap to inject the routed address
                $this->mailer->raw(
                    $envelope->to($email),
                    $this->renderContent($mailable->content()),
                );
                return;
            }
        }

        $this->mailer->send($mailable);
    }

    private function renderContent(Content $content): string
    {
        return $content->html ?? $content->text ?? '';
    }
}
