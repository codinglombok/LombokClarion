<?php

declare(strict_types=1);

use LombokClarion\Console\BuiltIn\MakeSeederCommand;
use LombokClarion\Console\BuiltIn\SeedCommand;
use LombokClarion\Console\BuiltIn\SeedStatusCommand;
use LombokClarion\Persistence\ConnectionConfig;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Persistence\Factory;
use LombokClarion\Persistence\SeedContext;
use LombokClarion\Persistence\Seeder;
use LombokClarion\Persistence\SeederRunner;

/**
 * Stage 16 slice B — seeding framework: Seeder + SeederRunner (tracked,
 * one transaction per seeder), deterministic Factory, and the seed /
 * seed:status / make:seeder commands.
 */

final class Test_SeederOne implements Seeder
{
    public function seed(SeedContext $context): void
    {
        $context->table('seed_one')->insert([
            'id' => $context->factory()->id(),
            'label' => 'one',
        ]);
    }
}

final class Test_SeederTwo implements Seeder
{
    public function seed(SeedContext $context): void
    {
        $context->table('seed_two')->insert([
            'id' => $context->factory()->id(),
            'label' => 'two',
        ]);
    }
}

/** Inserts, then throws — to prove the whole thing rolls back. */
final class Test_SeederExploding implements Seeder
{
    public function seed(SeedContext $context): void
    {
        $context->table('seed_one')->insert([
            'id' => $context->factory()->id(),
            'label' => 'doomed',
        ]);

        throw new RuntimeException('seeder blew up halfway');
    }
}

/** In the manifest but not a Seeder — the loud-error case. */
final class Test_NotASeeder
{
}

/**
 * @return array{0: ConnectionManager, 1: PDO}
 */
function seeding_manager(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE seed_one (id VARCHAR(32) PRIMARY KEY, label VARCHAR(32) NOT NULL)');
    $pdo->exec('CREATE TABLE seed_two (id VARCHAR(32) PRIMARY KEY, label VARCHAR(32) NOT NULL)');

    $manager = new ConnectionManager([
        'default' => ConnectionConfig::provided('sqlite', $pdo),
    ], 'default');

    return [$manager, $pdo];
}

function seeding_context(ConnectionManager $manager, int $seed = 1): SeedContext
{
    return new SeedContext($manager, new Factory($seed));
}

function seeding_rows(PDO $pdo, string $table): int
{
    return (int) $pdo->query("SELECT COUNT(*) AS c FROM $table")->fetch(PDO::FETCH_ASSOC)['c'];
}

function seeding_tmpdir(): string
{
    $dir = sys_get_temp_dir() . '/lc-make-seeder-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    return $dir;
}

// ------------------------------------------------------------ Factory

test('Factory is deterministic: the same seed produces the same values', function (): void {
    $a = new Factory(4242);
    $b = new Factory(4242);

    assertSame($a->id(), $b->id(), 'ids diverged at the same seed');
    assertSame($a->words(3), $b->words(3), 'words diverged at the same seed');
    assertSame($a->int(1, 1_000_000), $b->int(1, 1_000_000), 'ints diverged at the same seed');
    assertSame($a->bool(), $b->bool(), 'bools diverged at the same seed');
});

test('Factory: a different seed produces different values', function (): void {
    $a = new Factory(4242);
    $c = new Factory(4243);

    assertTrue($a->id() !== $c->id(), 'two seeds produced the same id');
});

test('Factory reports its own seed so a run can be reproduced', function (): void {
    assertSame(4242, (new Factory(4242))->seed());
});

test('Factory::sequence() is monotonic — the unique-but-unread column case', function (): void {
    $f = new Factory(7);

    assertSame(1, $f->sequence());
    assertSame(2, $f->sequence());
    assertSame(3, $f->sequence());
});

test('Factory emails land in .invalid so seeded rows can never reach a real inbox', function (): void {
    $f = new Factory(7);

    assertTrue(str_ends_with($f->email(), '@example.invalid'), 'email escaped the reserved TLD');
});

test('Factory rejects degenerate ranges and empty choices rather than guessing', function (): void {
    $f = new Factory(7);

    assertThrows(InvalidArgumentException::class, fn () => $f->int(10, 1));
    assertThrows(InvalidArgumentException::class, fn () => $f->pick([]));
    assertThrows(InvalidArgumentException::class, fn () => $f->words(0));
});

// ------------------------------------------------------------ runner

test('SeederRunner runs the manifest in order and records what ran', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');

    $ran = $runner->seed([Test_SeederOne::class, Test_SeederTwo::class], seeding_context($manager));

    assertSame([Test_SeederOne::class, Test_SeederTwo::class], $ran);
    assertSame(1, seeding_rows($pdo, 'seed_one'));
    assertSame(1, seeding_rows($pdo, 'seed_two'));
    assertSame([Test_SeederOne::class, Test_SeederTwo::class], $runner->appliedSeeders());
});

test('a second seed() run is a no-op — tracking is what stops silent duplication', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');
    $manifest = [Test_SeederOne::class];

    $runner->seed($manifest, seeding_context($manager));
    $second = $runner->seed($manifest, seeding_context($manager));

    assertSame([], $second, 'the second run re-ran something');
    assertSame(1, seeding_rows($pdo, 'seed_one'), 'rows were duplicated');
});

test('a failing seeder rolls back its rows AND its seeders record together', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');

    assertThrows(
        RuntimeException::class,
        fn () => $runner->seed([Test_SeederExploding::class], seeding_context($manager))
    );

    // The point of one transaction covering both: "recorded as seeded" and
    // "actually seeded" cannot disagree.
    assertSame(0, seeding_rows($pdo, 'seed_one'), 'a half-applied seeder left rows behind');
    assertSame([], $runner->appliedSeeders(), 'a failed seeder was recorded as applied');
});

test('a manifest entry that is not a Seeder is a loud error, not a skip', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');

    assertThrows(
        RuntimeException::class,
        fn () => $runner->seed([Test_NotASeeder::class], seeding_context($manager))
    );
});

test('seedOnly() refuses a class that is not in the manifest', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');

    assertThrows(
        RuntimeException::class,
        fn () => $runner->seedOnly([Test_SeederOne::class], [Test_SeederTwo::class], seeding_context($manager), true)
    );
});

test('seedOnly() without force skips an already-applied seeder', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');
    $manifest = [Test_SeederOne::class];

    $runner->seed($manifest, seeding_context($manager));
    $again = $runner->seedOnly($manifest, [Test_SeederOne::class], seeding_context($manager), false);

    assertSame([], $again);
    assertSame(1, seeding_rows($pdo, 'seed_one'));
});

test('seedOnly() with force re-runs, and does not write a second seeders row', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');
    $manifest = [Test_SeederOne::class];

    $runner->seed($manifest, seeding_context($manager, 1));
    // A DIFFERENT seed, because the same seed would regenerate the same id
    // and collide on the primary key — which is itself asserted below.
    $again = $runner->seedOnly($manifest, [Test_SeederOne::class], seeding_context($manager, 2), true);

    assertSame([Test_SeederOne::class], $again);
    assertSame(2, seeding_rows($pdo, 'seed_one'));
    assertSame([Test_SeederOne::class], $runner->appliedSeeders(), 'force wrote a duplicate tracking row');
});

test('a forced re-run at the SAME seed collides and rolls back — determinism refuses to duplicate', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');
    $manifest = [Test_SeederOne::class];

    $runner->seed($manifest, seeding_context($manager, 1));

    assertThrows(
        PDOException::class,
        fn () => $runner->seedOnly($manifest, [Test_SeederOne::class], seeding_context($manager, 1), true)
    );

    assertSame(1, seeding_rows($pdo, 'seed_one'), 'the collided re-run still duplicated rows');
});

test('forget() clears the record without touching the rows — there is no down() for data', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');

    $runner->seed([Test_SeederOne::class], seeding_context($manager));

    assertTrue($runner->forget(Test_SeederOne::class), 'forget() reported nothing removed');
    assertSame([], $runner->appliedSeeders());
    assertSame(1, seeding_rows($pdo, 'seed_one'), 'forget() deleted rows it has no business deleting');
    assertTrue(!$runner->forget(Test_SeederOne::class), 'forget() claimed a second removal');
});

// ------------------------------------------------------------ context

test('SeedContext resolves the connection once and reports its name', function (): void {
    [$manager] = seeding_manager();

    assertSame('default', seeding_context($manager)->connectionName());
});

// ------------------------------------------------------------ seed command

test('seed runs pending seeders and exits 0', function (): void {
    [$manager, $pdo] = seeding_manager();
    $command = new SeedCommand($manager, [Test_SeederOne::class]);

    ob_start();
    $exit = $command->run([]);
    ob_end_clean();

    assertSame(0, $exit);
    assertSame(1, seeding_rows($pdo, 'seed_one'));
});

test('seed prints the factory seed on every run — an unprinted seed cannot be reproduced', function (): void {
    [$manager] = seeding_manager();
    $command = new SeedCommand($manager, [Test_SeederOne::class]);

    ob_start();
    $command->run(['--seed=8675309']);
    $output = (string) ob_get_clean();

    assertTrue(str_contains($output, '8675309'), 'the seed was not echoed back');
});

test('seed --force without --only is refused: the destructive case is not the shortest to type', function (): void {
    [$manager, $pdo] = seeding_manager();
    $command = new SeedCommand($manager, [Test_SeederOne::class]);

    ob_start();
    $exit = $command->run(['--force']);
    ob_end_clean();

    assertSame(1, $exit);
    assertSame(0, seeding_rows($pdo, 'seed_one'), 'the refused run seeded anyway');
});

test('seed --only accepts the short class name as well as the FQCN', function (): void {
    [$manager, $pdo] = seeding_manager();
    $command = new SeedCommand($manager, [Test_SeederOne::class, Test_SeederTwo::class]);

    ob_start();
    $exit = $command->run(['--only=Test_SeederOne']);
    ob_end_clean();

    assertSame(0, $exit);
    assertSame(1, seeding_rows($pdo, 'seed_one'));
    assertSame(0, seeding_rows($pdo, 'seed_two'), '--only seeded something it was not asked for');
});

test('seed rejects an unknown seeder name rather than seeding nothing quietly', function (): void {
    [$manager] = seeding_manager();
    $command = new SeedCommand($manager, [Test_SeederOne::class]);

    ob_start();
    $exit = $command->run(['--only=NoSuchSeeder']);
    ob_end_clean();

    assertSame(1, $exit);
});

test('seed rejects a non-integer --seed rather than silently using 0', function (): void {
    [$manager] = seeding_manager();
    $command = new SeedCommand($manager, [Test_SeederOne::class]);

    ob_start();
    $exit = $command->run(['--seed=abc']);
    ob_end_clean();

    assertSame(1, $exit);
});

test('seed rejects a typo\'d flag and an unknown connection, listing what is defined', function (): void {
    [$manager] = seeding_manager();
    $command = new SeedCommand($manager, [Test_SeederOne::class]);

    ob_start();
    $typo = $command->run(['--connnection=default']);
    $unknown = $command->run(['--connection=nope']);
    ob_end_clean();

    assertSame(1, $typo);
    assertSame(1, $unknown);
});

// ------------------------------------------------------------ seed:status

test('seed:status reports every manifest entry as pending on a fresh database', function (): void {
    [$manager] = seeding_manager();
    $command = new SeedStatusCommand($manager, [Test_SeederOne::class, Test_SeederTwo::class]);

    ob_start();
    $exit = $command->run([]);
    $output = (string) ob_get_clean();

    assertSame(0, $exit);
    assertTrue(str_contains($output, '0 seeded, 2 pending'), "unexpected summary: $output");
    assertTrue(str_contains($output, 'PENDING'), 'pending entries were not marked');
});

test('seed:status --strict turns pending into exit 1 — the deploy gate', function (): void {
    [$manager] = seeding_manager();
    $command = new SeedStatusCommand($manager, [Test_SeederOne::class]);

    ob_start();
    $exit = $command->run(['--strict']);
    ob_end_clean();

    assertSame(1, $exit);
});

test('seed:status is read-only: it seeds nothing', function (): void {
    [$manager, $pdo] = seeding_manager();
    $command = new SeedStatusCommand($manager, [Test_SeederOne::class]);

    ob_start();
    $command->run([]);
    ob_end_clean();

    assertSame(0, seeding_rows($pdo, 'seed_one'));
});

test('seed:status exits 0 once everything has run', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');
    $runner->seed([Test_SeederOne::class], seeding_context($manager));

    $command = new SeedStatusCommand($manager, [Test_SeederOne::class]);

    ob_start();
    $exit = $command->run([]);
    $output = (string) ob_get_clean();
    ob_start();
    $strict = $command->run(['--strict']);
    ob_end_clean();

    assertSame(0, $exit);
    assertSame(0, $strict, '--strict failed with nothing pending');
    assertTrue(str_contains($output, '1 seeded, 0 pending'), "unexpected summary: $output");
});

test('a recorded seeder absent from the manifest is an orphan — exit 1 with or without --strict', function (): void {
    [$manager, $pdo] = seeding_manager();
    $runner = new SeederRunner($pdo, 'sqlite');
    $runner->seed([Test_SeederOne::class, Test_SeederTwo::class], seeding_context($manager));

    // Test_SeederTwo ran, then left the manifest: rows exist that nothing
    // accounts for.
    $command = new SeedStatusCommand($manager, [Test_SeederOne::class]);

    ob_start();
    $plain = $command->run([]);
    ob_end_clean();
    ob_start();
    $strict = $command->run(['--strict']);
    ob_end_clean();

    assertSame(1, $plain, 'an orphan passed without --strict');
    assertSame(1, $strict);
});

// ------------------------------------------------------------ make:seeder

test('make:seeder writes a stub that parses', function (): void {
    $dir = seeding_tmpdir();
    $command = new MakeSeederCommand($dir, 'App\\Seeders');

    ob_start();
    $exit = $command->run(['DemoSeeder', '--table=widgets']);
    ob_end_clean();

    assertSame(0, $exit);

    $path = $dir . '/DemoSeeder.php';
    assertTrue(file_exists($path), 'no file was written');

    $lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    assertTrue(
        is_string($lint) && str_contains($lint, 'No syntax errors'),
        'generated stub does not parse: ' . (string) $lint
    );

    $source = (string) file_get_contents($path);
    assertTrue(str_contains($source, 'namespace App\\Seeders;'), 'namespace was not the configured one');
    assertTrue(str_contains($source, 'implements Seeder'), 'stub does not implement Seeder');
    assertTrue(str_contains($source, "table('widgets')"), '--table did not reach the stub');
});

test('make:seeder never overwrites an existing file', function (): void {
    $dir = seeding_tmpdir();
    $command = new MakeSeederCommand($dir, 'App\\Seeders');

    ob_start();
    $command->run(['OnceSeeder']);
    $second = $command->run(['OnceSeeder']);
    ob_end_clean();

    assertSame(1, $second);
});

test('make:seeder rejects names that could disagree with the namespace or escape the directory', function (): void {
    $dir = seeding_tmpdir();
    $command = new MakeSeederCommand($dir, 'App\\Seeders');

    foreach (['lowercase', 'Has-Dash', 'App\\Nested\\Thing', '../Escape', ''] as $bad) {
        ob_start();
        $exit = $command->run([$bad]);
        ob_end_clean();

        assertSame(1, $exit, "accepted a bad class name: \"$bad\"");
    }

    ob_start();
    $badTable = $command->run(['GoodName', '--table=things; DROP TABLE users']);
    ob_end_clean();

    assertSame(1, $badTable, 'accepted a non-identifier table name');
});

test('make:seeder does not touch the manifest — registration and order stay the author\'s decision', function (): void {
    $dir = seeding_tmpdir();
    $manifest = $dir . '/seeders.php';
    file_put_contents($manifest, "<?php\n\nreturn [];\n");
    $before = (string) file_get_contents($manifest);

    $command = new MakeSeederCommand($dir, 'App\\Seeders', $manifest);

    ob_start();
    $exit = $command->run(['UnregisteredSeeder']);
    $output = (string) ob_get_clean();

    assertSame(0, $exit);
    assertSame($before, (string) file_get_contents($manifest), 'the generator edited the manifest');
    assertTrue(str_contains($output, 'order matters'), 'the paste instructions did not mention order');
});

test('make:seeder errors on a target directory that does not exist rather than creating one', function (): void {
    $command = new MakeSeederCommand('/nonexistent/path/for/sure', 'App\\Seeders');

    ob_start();
    $exit = $command->run(['SomeSeeder']);
    ob_end_clean();

    assertSame(1, $exit);
});
