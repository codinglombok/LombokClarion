<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

/**
 * RBAC lookups, kept behind an interface for the same reason as UserProvider:
 * the middleware and Gate need to ask "does this user have X", not "which JOIN
 * answers that". DatabaseRoleRepository is the shipped implementation.
 */
interface RoleRepository
{
    /** @return list<string> */
    public function rolesFor(string $userId): array;

    /** @return list<string> */
    public function permissionsFor(string $userId): array;
}
