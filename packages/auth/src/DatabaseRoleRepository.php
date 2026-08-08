<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

use PDO;

/**
 * The shipped RoleRepository: roles via role_user, permissions via the
 * role_user → permission_role → permissions chain.
 *
 * Every value is bound. The joins are written out rather than assembled from
 * user input, so this file passes audit:sql and the PHPStan rule with no
 * exemption and no TrustedDdl marker — which is the standard the rest of the
 * framework is held to and there is no reason auth should be the exception.
 */
final class DatabaseRoleRepository implements RoleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public function rolesFor(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.name FROM roles r '
            . 'INNER JOIN role_user ru ON ru.role_id = r.id '
            . 'WHERE ru.user_id = ? '
            . 'ORDER BY r.name ASC'
        );
        $stmt->execute([$userId]);

        return $this->column($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<string> */
    public function permissionsFor(string $userId): array
    {
        // DISTINCT because two roles may grant the same permission; without it a
        // user with both 'editor' and 'admin' would get 'post.edit' twice, which
        // is harmless for in_array() but wrong the moment anyone counts or
        // displays this list.
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.name FROM permissions p '
            . 'INNER JOIN permission_role pr ON pr.permission_id = p.id '
            . 'INNER JOIN role_user ru ON ru.role_id = pr.role_id '
            . 'WHERE ru.user_id = ? '
            . 'ORDER BY p.name ASC'
        );
        $stmt->execute([$userId]);

        return $this->column($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function column(array $rows): array
    {
        return array_values(array_map(static fn (array $row): string => (string) $row['name'], $rows));
    }
}
