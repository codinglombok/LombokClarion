<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Account\User;
use LombokClarion\Auth\Authenticatable;

/**
 * The adapter that lets a framework-free domain entity satisfy a framework
 * contract. This is the §3 boundary doing its job rather than getting in the
 * way: App\Domain\Account\User knows nothing about auth, and this thin wrapper
 * is where the two worlds meet.
 */
final class AuthenticatableUser implements Authenticatable
{
    public function __construct(public readonly User $user)
    {
    }

    public function getAuthId(): string
    {
        return $this->user->id;
    }

    public function getPasswordHash(): string
    {
        return $this->user->passwordHash;
    }
}
