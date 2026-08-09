<?php

declare(strict_types=1);

namespace LombokClarion\Notification;

/**
 * Base class for notifications. Subclass and implement via() + at least
 * one toXxx() method per channel declared in via().
 *
 *   class InvoicePaid extends Notification {
 *       public function __construct(private readonly Invoice $invoice) {}
 *       public function via(Notifiable $notifiable): array { return ['mail', 'database']; }
 *       public function toMail(Notifiable $n): MailMessage { ... }
 *       public function toDatabase(Notifiable $n): array { ... }
 *   }
 */
abstract class Notification
{
    /**
     * Channels this notification should be sent through.
     *
     * @return list<string> channel names ('mail', 'database', 'log', or custom)
     */
    abstract public function via(Notifiable $notifiable): array;
}
