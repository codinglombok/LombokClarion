<?php

declare(strict_types=1);

/**
 * The seeder manifest: a hand-written, ordered list — never a directory scan,
 * for the same reason bootstrap/migrations.php is not one (§2.5).
 *
 * ORDER MATTERS the same way it does for migrations: a seeder inserting rows
 * that reference another table's rows must come after the seeder that created
 * them. make:seeder deliberately does not edit this file.
 */

use App\Infrastructure\Persistence\Seeders\DemoWidgetsSeeder;

return [
    DemoWidgetsSeeder::class,
];
