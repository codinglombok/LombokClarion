<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

/**
 * A policy answers one question about one subject: may this user do this thing
 * to this object? The object is whatever your domain calls it — a Post, an
 * Invoice, a Tenant — so it is typed `mixed` here and typed properly in your
 * implementation.
 */
interface Policy
{
    public function allows(string $ability, Authenticatable $user, mixed $subject = null): bool;
}
