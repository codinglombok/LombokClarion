<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Migrations;

use LombokClarion\Persistence\Migration;
use LombokClarion\Persistence\SchemaBuilder;

final class CreateWidgetsTable implements Migration
{
    public function up(SchemaBuilder $schema): void
    {
        $schema->createTable('widgets', [
            'id' => 'VARCHAR(32) PRIMARY KEY',
            'name' => 'VARCHAR(255) NOT NULL',
            'price_cents' => 'INTEGER NOT NULL',
        ]);
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropTable('widgets');
    }

    public function runsInTransaction(SchemaBuilder $schema): bool
    {
        return $schema->migrationsAreTransactionalByDefault();
    }
}
