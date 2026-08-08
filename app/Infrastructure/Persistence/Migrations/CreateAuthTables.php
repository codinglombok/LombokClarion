<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Migrations;

use LombokClarion\Persistence\Migration;
use LombokClarion\Persistence\SchemaBuilder;

/**
 * users + the four RBAC tables (§Stage 9). Listed by hand in
 * bootstrap/migrations.php like every other migration — nothing is discovered.
 *
 * The join tables use composite natural keys rather than surrogate ids: a user
 * either has a role or does not, so `(user_id, role_id)` is the fact, and making
 * it the primary key means the database refuses a duplicate grant instead of
 * trusting every caller to check first.
 */
final class CreateAuthTables implements Migration
{
    public function up(SchemaBuilder $schema): void
    {
        $schema->createTable('users', [
            'id' => 'VARCHAR(32) NOT NULL PRIMARY KEY',
            'email' => 'VARCHAR(255) NOT NULL UNIQUE',
            'password_hash' => 'VARCHAR(255) NOT NULL',
        ]);

        $schema->createTable('roles', [
            'id' => 'VARCHAR(32) NOT NULL PRIMARY KEY',
            'name' => 'VARCHAR(64) NOT NULL UNIQUE',
        ]);

        $schema->createTable('permissions', [
            'id' => 'VARCHAR(32) NOT NULL PRIMARY KEY',
            'name' => 'VARCHAR(128) NOT NULL UNIQUE',
        ]);

        $schema->createTable('role_user', [
            'user_id' => 'VARCHAR(32) NOT NULL',
            'role_id' => 'VARCHAR(32) NOT NULL',
        ], ['PRIMARY KEY (user_id, role_id)']);

        $schema->createTable('permission_role', [
            'role_id' => 'VARCHAR(32) NOT NULL',
            'permission_id' => 'VARCHAR(32) NOT NULL',
        ], ['PRIMARY KEY (role_id, permission_id)']);
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropTable('permission_role');
        $schema->dropTable('role_user');
        $schema->dropTable('permissions');
        $schema->dropTable('roles');
        $schema->dropTable('users');
    }

    public function runsInTransaction(SchemaBuilder $schema): bool
    {
        return $schema->migrationsAreTransactionalByDefault();
    }
}
