<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use LombokClarion\Auth\Authenticatable;
use LombokClarion\Auth\Policy;
use LombokClarion\Auth\RoleRepository;

/**
 * Registered by hand in bootstrap/services.php:
 *
 *   $gate->define('widget.delete', WidgetPolicy::class);
 *
 * A policy may have dependencies — it is built through the Gate's resolver
 * (the container), not with `new` inside the Gate.
 */
final class WidgetPolicy implements Policy
{
    public function __construct(private readonly RoleRepository $roles)
    {
    }

    public function allows(string $ability, Authenticatable $user, mixed $subject = null): bool
    {
        return match ($ability) {
            // Admins may delete anything; everyone else, nothing.
            'widget.delete' => in_array('admin', $this->roles->rolesFor($user->getAuthId()), true),
            default => false,
        };
    }
}
