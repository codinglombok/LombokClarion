<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

/**
 * The seam between auth and your storage. AuthManager never writes a query —
 * it asks this. Implement it against QueryBuilder, an ActiveRecord model, LDAP,
 * or an array in a test; nothing else in the package changes.
 */
interface UserProvider
{
    /**
     * Look a user up by whatever they type in the login form — email, username,
     * employee number. The package does not care which; that choice belongs to
     * your implementation.
     */
    public function findByIdentifier(string $identifier): ?Authenticatable;

    /**
     * Look a user up by the id previously returned from getAuthId(). Used when
     * rehydrating a request from its token.
     */
    public function findById(string $id): ?Authenticatable;
}
