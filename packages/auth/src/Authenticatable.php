<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

/**
 * The minimum an application's user type must expose for AuthManager to work.
 * Deliberately two methods: this package never needs a name, an email, or a
 * `->save()`, so it never asks for them. Your user class stays yours — it can
 * be an ActiveRecord Model, a plain domain entity under app/Domain, or a
 * readonly DTO, and it does not inherit from anything of ours.
 */
interface Authenticatable
{
    public function getAuthId(): string;

    public function getPasswordHash(): string;
}
