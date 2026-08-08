<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Account\User;
use LombokClarion\Auth\Authenticatable;
use LombokClarion\Auth\UserProvider;
use LombokClarion\Persistence\QueryBuilder;
use PDO;

/**
 * Reads users through QueryBuilder — every value bound, identifiers validated.
 */
final class DatabaseUserProvider implements UserProvider
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByIdentifier(string $identifier): ?Authenticatable
    {
        return $this->first('email', $identifier);
    }

    public function findById(string $id): ?Authenticatable
    {
        return $this->first('id', $id);
    }

    private function first(string $column, string $value): ?AuthenticatableUser
    {
        $row = (new QueryBuilder($this->pdo, 'users'))
            ->where($column, '=', $value)
            ->first();

        if ($row === null) {
            return null;
        }

        return new AuthenticatableUser(new User(
            id: (string) $row['id'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
        ));
    }
}
