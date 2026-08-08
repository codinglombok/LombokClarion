<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * A domain entity that happens to satisfy an auth contract — note the import
 * list: nothing from LombokClarion\*. app/Domain stays framework-free (§3), and
 * check-domain-boundary.php enforces it.
 *
 * That is why Authenticatable is implemented in the infrastructure layer
 * (see App\Infrastructure\Auth\AuthenticatableUser) rather than here: this class
 * cannot implement a framework interface without importing one.
 */
final class User
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $passwordHash,
    ) {
    }
}
