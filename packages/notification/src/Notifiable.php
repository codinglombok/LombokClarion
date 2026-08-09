<?php

declare(strict_types=1);

namespace LombokClarion\Notification;

/**
 * Any entity that can receive notifications. Typically implemented by
 * the User model/value object.
 */
interface Notifiable
{
    /**
     * Unique identifier for this notifiable (user ID, team ID, etc.).
     */
    public function notifiableId(): string|int;

    /**
     * Routing information for a given channel. For 'mail' this returns
     * an email address; for 'database' it may return null (uses notifiableId).
     */
    public function routeNotificationFor(string $channel): mixed;
}
