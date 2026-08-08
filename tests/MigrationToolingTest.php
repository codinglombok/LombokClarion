<?php

declare(strict_types=1);

use LombokClarion\Console\BuiltIn\MakeMigrationCommand;
use LombokClarion\Console\BuiltIn\MigrateStatusCommand;
use LombokClarion\Persistence\ConnectionConfig;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Persistence\Migration;
use LombokClarion\Persistence\MigrationRunner;
use LombokClarion\Persistence\SchemaBuilder;

/**
 * Stage 16 — migration tooling: migrate:status (read-only visibility) and
 * make:migration (stub generator that does NOT touch the manifest).
 */

final class Test_ToolingMigrationOne implements Migration
{
    public function up(SchemaBuilder $schema): void
    {
        $schema->createTable('tooling_one', ['id' => 'VARCHAR(32) PRIMARY KEY']);
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropTable('tooling_one');
    }

    public function runsInTransaction(SchemaBuilder $schema): bool
    {
        return $schema->migrationsAreTransactionalByDefault();
    }
}

final class Test_ToolingMigrationTwo implements Migration
{
    public function up(SchemaBuilder $schema): void
    {
        $schema->createTable('tooling_two', ['id' => 'VARCHAR(32) PRIMARY KEY']);
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropTable('tooling_two');
    }

    public function runsInTransaction(SchemaBuilder $schema): bool
    {
        return $schema->migrationsAreTransactionalByDefault();
    }
}

/**
 * @return array{0: ConnectionManager, 1: PDO}
 */
function tooling_manager(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $manager = new ConnectionManager([
        'default' => ConnectionConfig::provided('sqlite', $pdo),
    ], 'default');

    return [$manager, $pdo];
}

function tooling_tmpdir(): string
{
    $dir = sys_get_temp_dir() . '/lc-make-migration-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    return $dir;
}

// ---------------------------------------------------------------- status

test('migrate:status reports every manifest entry as pending on a fresh database', function (): void {
    [$manager] = tooling_manager();

    $command = new MigrateStatusCommand($manager, [
        Test_ToolingMigrationOne::class,
        Test_ToolingMigrationTwo::class,
    ]);

    ob_start();
    $exit = $command->run([]);
    $output = (string) ob_get_clean();

    assertSame(0, $exit, 'pending migrations alone are not a failure without --strict');
    assertTrue(str_contains($output, '0 ran, 2 pending'), 'summary line counts both as pending');
    assertSame(2, substr_count($output, 'PENDING '), 'both entries are marked PENDING');
});

test('migrate:status reports what has actually run, in manifest order', function (): void {
    [$manager, $pdo] = tooling_manager();
    $manifest = [Test_ToolingMigrationOne::class, Test_ToolingMigrationTwo::class];

    // Apply only the FIRST one, so the report has to distinguish them rather
    // than print one blanket state for the whole manifest.
    $runner = new MigrationRunner($pdo, new SchemaBuilder($pdo, 'sqlite'), 'sqlite');
    $runner->migrate([Test_ToolingMigrationOne::class]);

    ob_start();
    $exit = (new MigrateStatusCommand($manager, $manifest))->run([]);
    $output = (string) ob_get_clean();

    assertSame(0, $exit);
    assertTrue(str_contains($output, '1 ran, 1 pending'), 'summary distinguishes ran from pending');

    $ranAt = strpos($output, 'Test_ToolingMigrationOne');
    $pendingAt = strpos($output, 'Test_ToolingMigrationTwo');
    assertTrue($ranAt !== false && $pendingAt !== false, 'both classes are listed');
    assertTrue($ranAt < $pendingAt, 'listing follows manifest order, not applied-first order');
});

test('migrate:status --strict turns pending migrations into a failing exit code', function (): void {
    [$manager] = tooling_manager();

    ob_start();
    $exit = (new MigrateStatusCommand($manager, [Test_ToolingMigrationOne::class]))->run(['--strict']);
    ob_get_clean();

    assertSame(1, $exit, '--strict is the deploy gate: pending means not current');
});

test('migrate:status --strict succeeds once nothing is pending', function (): void {
    [$manager, $pdo] = tooling_manager();
    $manifest = [Test_ToolingMigrationOne::class];

    $runner = new MigrationRunner($pdo, new SchemaBuilder($pdo, 'sqlite'), 'sqlite');
    $runner->migrate($manifest);

    ob_start();
    $exit = (new MigrateStatusCommand($manager, $manifest))->run(['--strict']);
    ob_get_clean();

    assertSame(0, $exit);
});

test('migrate:status fails on an applied migration missing from the manifest (rollback cannot run it)', function (): void {
    [$manager, $pdo] = tooling_manager();

    // Applied, then dropped from the manifest — exactly the state
    // MigrationRunner::rollback() hard-errors on. Status is the earliest
    // place it can be seen.
    $runner = new MigrationRunner($pdo, new SchemaBuilder($pdo, 'sqlite'), 'sqlite');
    $runner->migrate([Test_ToolingMigrationOne::class, Test_ToolingMigrationTwo::class]);

    ob_start();
    $exit = (new MigrateStatusCommand($manager, [Test_ToolingMigrationOne::class]))->run([]);
    ob_get_clean();

    assertSame(1, $exit, 'an orphan is an error with or without --strict');
});

test('migrate:status rejects an unknown argument rather than reporting on the wrong connection', function (): void {
    [$manager] = tooling_manager();

    ob_start();
    $exit = (new MigrateStatusCommand($manager, []))->run(['--connnection=typo']);
    ob_get_clean();

    assertSame(1, $exit);
});

test('migrate:status rejects an unknown connection name and lists the defined ones', function (): void {
    [$manager] = tooling_manager();

    ob_start();
    $exit = (new MigrateStatusCommand($manager, []))->run(['--connection=nope']);
    ob_get_clean();

    assertSame(1, $exit);
});

// ------------------------------------------------------- make:migration

test('make:migration writes a syntactically valid Migration implementation', function (): void {
    $dir = tooling_tmpdir();

    ob_start();
    $exit = (new MakeMigrationCommand($dir, 'App\\Tooling\\Migrations'))->run(['CreateThingsTable', '--table=things']);
    ob_get_clean();

    assertSame(0, $exit);

    $path = $dir . '/CreateThingsTable.php';
    assertTrue(is_file($path), 'the file is written under the configured directory');

    $source = (string) file_get_contents($path);
    assertTrue(str_contains($source, 'declare(strict_types=1);'), 'strict_types everywhere is not optional');
    assertTrue(str_contains($source, 'namespace App\\Tooling\\Migrations;'), 'namespace comes from configuration');
    assertTrue(str_contains($source, 'implements Migration'), 'the stub implements the contract');
    assertTrue(str_contains($source, "createTable('things'"), '--table fills the up() body');
    assertTrue(str_contains($source, "dropTable('things')"), 'down() is authored, not left empty (#45)');

    // The generated file must actually parse — a stub that needs hand-fixing
    // before it runs is not a working generator.
    $check = shell_exec(sprintf('php -l %s 2>&1', escapeshellarg($path)));
    assertTrue(is_string($check) && str_contains($check, 'No syntax errors'), 'generated file parses');

    unlink($path);
    rmdir($dir);
});

test('make:migration never edits the manifest — it prints the line to register by hand', function (): void {
    $dir = tooling_tmpdir();

    ob_start();
    (new MakeMigrationCommand($dir, 'App\\Tooling\\Migrations'))->run(['CreateOrdersTable', '--table=orders']);
    $output = (string) ob_get_clean();

    // §2.5: registration is explicit. Order is a decision a generator cannot
    // make, so it tells you rather than guessing.
    assertTrue(str_contains($output, 'Register it yourself'), 'the caller is told to register it');
    assertTrue(str_contains($output, 'CreateOrdersTable::class,'), 'the exact line to paste is shown');
    assertTrue(str_contains($output, 'order matters'), 'the reason it is not automatic is stated');

    unlink($dir . '/CreateOrdersTable.php');
    rmdir($dir);
});

test('make:migration refuses to overwrite an existing migration', function (): void {
    $dir = tooling_tmpdir();
    $command = new MakeMigrationCommand($dir, 'App\\Tooling\\Migrations');

    ob_start();
    $command->run(['CreateOnceTable', '--table=once']);
    ob_get_clean();

    $original = (string) file_get_contents($dir . '/CreateOnceTable.php');

    ob_start();
    $exit = $command->run(['CreateOnceTable', '--table=different']);
    ob_get_clean();

    assertSame(1, $exit, 'clobbering an applied migration has no undo');
    assertSame($original, (string) file_get_contents($dir . '/CreateOnceTable.php'), 'the file is untouched');

    unlink($dir . '/CreateOnceTable.php');
    rmdir($dir);
});

test('make:migration rejects a name that is a path or namespace, not a class', function (): void {
    $dir = tooling_tmpdir();
    $command = new MakeMigrationCommand($dir, 'App\\Tooling\\Migrations');

    foreach (['../Escape', 'App\\Nested\\Thing', 'lowercase', 'Has-Dash', ''] as $bad) {
        ob_start();
        $exit = $command->run([$bad]);
        ob_get_clean();

        assertSame(1, $exit, sprintf('"%s" must be rejected', $bad));
    }

    assertSame([], array_values(array_diff((array) scandir($dir), ['.', '..'])), 'nothing was written');

    rmdir($dir);
});

test('make:migration rejects a table name that is not a bare identifier', function (): void {
    $dir = tooling_tmpdir();

    ob_start();
    $exit = (new MakeMigrationCommand($dir, 'App\\Tooling\\Migrations'))
        ->run(['CreateBadTable', '--table=things; DROP TABLE users']);
    ob_get_clean();

    assertSame(1, $exit, 'the table name lands inside generated SQL — it is validated at the boundary');
    assertTrue(!is_file($dir . '/CreateBadTable.php'), 'nothing was written');

    rmdir($dir);
});

test('make:migration without --table still authors down() as a TODO, never a silent no-op', function (): void {
    $dir = tooling_tmpdir();

    ob_start();
    $exit = (new MakeMigrationCommand($dir, 'App\\Tooling\\Migrations'))->run(['AlterSomething']);
    $output = (string) ob_get_clean();

    assertSame(0, $exit);

    $source = (string) file_get_contents($dir . '/AlterSomething.php');
    assertTrue(str_contains($source, 'cannot be rolled back'), 'the empty down() says why it must be filled in');
    assertTrue(str_contains($output, 'fill them in before migrating'), 'the caller is warned the stub is incomplete');

    unlink($dir . '/AlterSomething.php');
    rmdir($dir);
});

test('make:migration fails loudly when the target directory does not exist', function (): void {
    ob_start();
    $exit = (new MakeMigrationCommand('/nonexistent/path/for/sure', 'App\\Tooling\\Migrations'))
        ->run(['CreateThingTable']);
    ob_get_clean();

    assertSame(1, $exit, 'a misconfigured directory is an error, not a directory to create silently');
});
