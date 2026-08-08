<?php

declare(strict_types=1);

/**
 * The `laravel-flavor` preset: every piece of "magic" the framework
 * offers, switched on by ONE greppable line — and provably off before it.
 *
 *   php examples/04-laravel-flavor.php
 */

require __DIR__ . '/../autoload.php';

use LombokClarion\Container\Container;
use LombokClarion\LaravelFlavor\DB;
use LombokClarion\LaravelFlavor\LaravelFlavor;
use LombokClarion\Persistence\ConnectionConfig;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Security\PasswordHasher;
use LombokClarion\Security\Argon2idPasswordHasher;
use LombokClarion\Security\SecurityConfig;
use LombokClarion\Facades\Hash;

$container = new Container();
$container->instance(ConnectionManager::class, new ConnectionManager([
    'default' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
], default: 'default'));
$container->singleton(PasswordHasher::class, fn () => new Argon2idPasswordHasher(new SecurityConfig()));

// Before the line: nothing static works. The magic has no default-on path.
try {
    DB::connection();
} catch (RuntimeException $e) {
    echo "before enable(): " . substr($e->getMessage(), 0, 40) . "...\n";
}

// The line. `grep -rn "LaravelFlavor::enable"` is the whole audit.
$globalMiddleware = LaravelFlavor::enable($container);

echo "global middleware: " . implode(', ', $globalMiddleware) . "\n";

DB::connection()->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT)');
DB::table('notes')->insert(['body' => 'facades are sugar over the same container']);
echo "notes: " . count(DB::table('notes')->get()) . "\n";
echo "hash verifies: " . var_export(Hash::verify('s3cret', Hash::hash('s3cret')), true) . "\n";

LaravelFlavor::disable();
