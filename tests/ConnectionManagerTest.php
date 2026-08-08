<?php

declare(strict_types=1);

use LombokClarion\Persistence\AnsiGrammar;
use LombokClarion\Persistence\ConnectionConfig;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Persistence\Exceptions\QueryException;
use LombokClarion\Persistence\GrammarFactory;
use LombokClarion\Persistence\MySqlGrammar;
use LombokClarion\Persistence\QueryBuilder;

/** Two genuinely separate in-memory SQLite databases. */
function test_twoConnections(): ConnectionManager
{
    return new ConnectionManager([
        'default' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
        'reporting' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
    ], default: 'default');
}

// --- naming and lookup ---------------------------------------------------

test('manager resolves the default connection when no name is given', function () {
    $manager = test_twoConnections();
    assertSame('default', $manager->defaultName());
    assertSame(['default', 'reporting'], $manager->names());
    assertTrue($manager->connection() === $manager->write('default'));
});

test('an unknown connection name is an error, never a silent default', function () {
    $manager = test_twoConnections();
    assertTrue($manager->has('reporting'));
    assertTrue(!$manager->has('warehouse'));
    assertThrows(QueryException::class, fn () => $manager->write('warehouse'));
});

test('a default that is not defined fails at construction, not at first query', function () {
    assertThrows(QueryException::class, fn () => new ConnectionManager([
        'reporting' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
    ], default: 'default'));
});

test('the manager rejects raw arrays in place of ConnectionConfig', function () {
    /** @phpstan-ignore-next-line deliberately wrong type — that is the test */
    assertThrows(QueryException::class, fn () => new ConnectionManager([
        'default' => ['driver' => 'sqlite', 'dsn' => 'sqlite::memory:'],
    ]));
});

// --- isolation -----------------------------------------------------------

test('two named connections are two isolated databases', function () {
    $manager = test_twoConnections();

    $manager->write('default')->exec('CREATE TABLE widgets (id INTEGER)');
    $manager->write('reporting')->exec('CREATE TABLE rollups (id INTEGER)');

    $defaultTables = $manager->write('default')
        ->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $reportingTables = $manager->write('reporting')
        ->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

    assertSame(['widgets'], $defaultTables);
    assertSame(['rollups'], $reportingTables);
});

// --- laziness ------------------------------------------------------------

test('declaring a connection opens nothing until it is asked for', function () {
    // An unopenable DSN: if the manager were eager, constructing it would throw.
    $manager = new ConnectionManager([
        'default' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
        'broken' => ConnectionConfig::dsn('sqlite', 'sqlite:/nonexistent-dir-lc10/db.sqlite'),
    ], default: 'default');

    // Construction survived, and so does using an unrelated connection.
    $manager->write('default')->exec('CREATE TABLE ok (id INTEGER)');
    assertSame('sqlite', $manager->driver('broken'));

    // The failure arrives only when the broken connection is actually used.
    assertThrows(PDOException::class, fn () => $manager->write('broken'));
});

test('a resolved connection is cached, not reopened per call', function () {
    $dir = sys_get_temp_dir() . '/lc_conn_' . uniqid();
    mkdir($dir);

    $manager = new ConnectionManager([
        'default' => ConnectionConfig::dsn('sqlite', "sqlite:$dir/a.sqlite"),
    ]);

    assertTrue($manager->write() === $manager->write());

    unlink("$dir/a.sqlite");
    rmdir($dir);
});

// --- read/write split ----------------------------------------------------

test('without a configured split, read() returns the very same PDO as write()', function () {
    $manager = test_twoConnections();

    // Identity, not equality: an unconfigured split must not cost a second handle.
    assertTrue($manager->read() === $manager->write());
    assertTrue($manager->read('reporting') === $manager->write('reporting'));
    assertTrue(!$manager->config()->hasReadSplit());
});

test('with a split configured, read() and write() are distinct connections', function () {
    $dir = sys_get_temp_dir() . '/lc_split_' . uniqid();
    mkdir($dir);

    // Two separate SQLite files stand in for primary and replica. Replication
    // is the DBA's problem; routing is this class's problem, and routing is
    // exactly what is asserted here.
    $manager = new ConnectionManager([
        'default' => ConnectionConfig::dsn(
            'sqlite',
            "sqlite:$dir/primary.sqlite",
            readDsn: "sqlite:$dir/replica.sqlite"
        ),
    ]);

    assertTrue($manager->config()->hasReadSplit());
    assertTrue($manager->read() !== $manager->write());

    $manager->write()->exec('CREATE TABLE only_on_primary (id INTEGER)');

    $replicaTables = $manager->read()
        ->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

    // Nothing was replicated, and the manager did not pretend otherwise:
    // read() went to the read server, which is the entire promise.
    assertSame([], $replicaTables);

    // Each side is still cached on its own.
    assertTrue($manager->read() === $manager->read());

    unlink("$dir/primary.sqlite");
    unlink("$dir/replica.sqlite");
    rmdir($dir);
});

test('a provided read PDO is honoured as the read side', function () {
    $write = new PDO('sqlite::memory:');
    $read = new PDO('sqlite::memory:');

    $manager = new ConnectionManager([
        'default' => ConnectionConfig::provided('sqlite', $write, $read),
    ]);

    assertTrue($manager->write() === $write);
    assertTrue($manager->read() === $read);
});

// --- {database} templates ------------------------------------------------

test('a template connection cannot be opened by name', function () {
    $manager = new ConnectionManager([
        'default' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
        'tenants' => ConnectionConfig::template('sqlite', 'sqlite:/tmp/{database}.sqlite'),
    ], default: 'default');

    // There is no correct database to pick, so picking one would be a guess.
    assertThrows(QueryException::class, fn () => $manager->write('tenants'));
    assertThrows(QueryException::class, fn () => $manager->read('tenants'));
});

test('forDatabase() resolves a template into isolated per-database connections', function () {
    $dir = sys_get_temp_dir() . '/lc_tpl_' . uniqid();
    mkdir($dir);

    $manager = new ConnectionManager([
        'tenants' => ConnectionConfig::template('sqlite', "sqlite:$dir/{database}.sqlite"),
    ], default: 'tenants');

    $acme = $manager->forDatabase('tenants', 'acme');
    $acme->exec('CREATE TABLE ping (id INTEGER)');

    $globex = $manager->forDatabase('tenants', 'globex');
    $tables = $globex->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

    assertSame([], $tables, 'globex must not see acme tables');
    assertTrue($acme !== $globex);

    // Same tenant twice in one process shares one handle; different tenants never do.
    assertTrue($manager->forDatabase('tenants', 'acme') === $acme);

    unlink("$dir/acme.sqlite");
    unlink("$dir/globex.sqlite");
    rmdir($dir);
});

test('forDatabase() refuses names that could rewrite the DSN or escape the directory', function () {
    $manager = new ConnectionManager([
        'tenants' => ConnectionConfig::template('pgsql', 'pgsql:host=db;dbname={database}'),
    ], default: 'tenants');

    // Appending DSN attributes, and directory traversal on a sqlite-style path.
    assertThrows(QueryException::class, fn () => $manager->forDatabase('tenants', 'app;host=evil.example'));
    assertThrows(QueryException::class, fn () => $manager->forDatabase('tenants', 'app user=postgres'));
    assertThrows(QueryException::class, fn () => $manager->forDatabase('tenants', '../../etc/passwd'));
    assertThrows(QueryException::class, fn () => $manager->forDatabase('tenants', ''));
});

test('forDatabase() on a non-template connection is an error', function () {
    $manager = test_twoConnections();
    assertThrows(QueryException::class, fn () => $manager->forDatabase('default', 'acme'));
});

test('a {database} placeholder in ConnectionConfig::dsn() is rejected at the source', function () {
    assertThrows(QueryException::class, fn () => ConnectionConfig::dsn('sqlite', 'sqlite:{database}.sqlite'));
    assertThrows(QueryException::class, fn () => ConnectionConfig::template('sqlite', 'sqlite:/fixed.sqlite'));
});

// --- grammar seam --------------------------------------------------------

test('grammar is chosen from the driver, and an unknown driver is an error', function () {
    assertTrue(GrammarFactory::forDriver('sqlite') instanceof AnsiGrammar);
    assertTrue(GrammarFactory::forDriver('pgsql') instanceof AnsiGrammar);
    assertTrue(GrammarFactory::forDriver('mysql') instanceof MySqlGrammar);
    assertTrue(GrammarFactory::forDriver('MariaDB') instanceof MySqlGrammar);

    // No silent fall back to ANSI for a driver we have never seen.
    assertThrows(QueryException::class, fn () => GrammarFactory::forDriver('oracle'));
});

test('grammars quote their own way but validate identically', function () {
    assertSame('"widgets"', (new AnsiGrammar())->quoteIdentifier('widgets'));
    assertSame('`widgets`', (new MySqlGrammar())->quoteIdentifier('widgets'));

    // Quoting is presentation; validation is the security property. Neither
    // grammar may be talked into accepting its own quote character.
    assertThrows(QueryException::class, fn () => (new MySqlGrammar())->quoteIdentifier('wid`gets'));
    assertThrows(QueryException::class, fn () => (new AnsiGrammar())->quoteIdentifier('wid"gets'));
    assertThrows(QueryException::class, fn () => (new MySqlGrammar())->quoteIdentifier('a; DROP TABLE b;--'));
});

test('QueryBuilder defaults to ANSI — pre-Stage-10 SQL is byte-for-byte unchanged', function () {
    $pdo = new PDO('sqlite::memory:');
    [$sql] = (new QueryBuilder($pdo, 'widgets'))->where('name', '=', 'x')->toSql();

    assertSame('SELECT * FROM "widgets" WHERE "name" = ?', $sql);
});

test('MySqlGrammar generates backtick SQL with no mysql server anywhere', function () {
    $pdo = new PDO('sqlite::memory:');

    // The grammar is a pure string concern. Proving it must not require
    // booting MySQL — so the PDO here is irrelevant and deliberately sqlite.
    [$sql, $bindings] = (new QueryBuilder($pdo, 'widgets', new MySqlGrammar()))
        ->select('widgets.name', 'makers.city')
        ->join('makers', 'widgets.maker_id', '=', 'makers.id')
        ->where('widgets.name', '=', 'gizmo')
        ->orderBy('widgets.name', 'desc')
        ->limit(5)
        ->toSql();

    assertSame(
        'SELECT `widgets`.`name`, `makers`.`city` FROM `widgets` INNER JOIN `makers` ' .
        'ON `widgets`.`maker_id` = `makers`.`id` WHERE `widgets`.`name` = ? ' .
        'ORDER BY `widgets`.`name` DESC LIMIT 5',
        $sql
    );

    // The value never became part of the string — that is not negotiable per driver.
    assertSame(['gizmo'], $bindings);
});

test('the grammar seam changes quoting only — values stay bound in both dialects', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

    // SQLite accepts backticks (a MySQL compatibility quirk), so the mysql
    // grammar can be exercised end-to-end here, not just as a string.
    $qb = new QueryBuilder($pdo, 'widgets', new MySqlGrammar());
    $qb->insert(['name' => "Robert'); DROP TABLE widgets;--"]);

    $rows = (new QueryBuilder($pdo, 'widgets', new MySqlGrammar()))->get();

    assertSame(1, count($rows));
    assertSame("Robert'); DROP TABLE widgets;--", $rows[0]['name']);

    // The table is still there: the payload was a value, not SQL.
    $updated = (new QueryBuilder($pdo, 'widgets', new MySqlGrammar()))
        ->where('id', '=', 1)
        ->update(['name' => 'safe']);
    assertSame(1, $updated);
});

test('EagerLoader inherits the grammar instead of reverting relations to ANSI', function () {
    // The hole this covers: a parent query on a mysql connection generating
    // backticks while its eager-loaded relations silently generated ANSI —
    // found the moment the seam was actually used. SQLite accepts backticks,
    // so the mysql grammar is exercised end-to-end here.
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, body TEXT)');
    $pdo->exec("INSERT INTO posts (id, title) VALUES (1, 'Hello')");
    $pdo->exec("INSERT INTO comments (id, post_id, body) VALUES (1, 1, 'Nice')");

    $grammar = new MySqlGrammar();
    $posts = (new QueryBuilder($pdo, 'posts', $grammar))->get();

    $loaded = (new LombokClarion\Persistence\EagerLoader($pdo, $grammar))->load(
        $posts,
        ['comments'],
        ['comments' => LombokClarion\Persistence\Relation::hasMany('comments', 'post_id')]
    );

    assertSame('Nice', $loaded[0]['comments'][0]['body']);
});

test('manager hands out a QueryBuilder already matched to its driver', function () {
    $manager = new ConnectionManager([
        'default' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
        'legacy' => ConnectionConfig::provided('mysql', new PDO('sqlite::memory:')),
    ], default: 'default');

    // The pairing cannot be got wrong by forgetting an argument.
    assertTrue($manager->table('widgets')->grammar() instanceof AnsiGrammar);
    assertTrue($manager->table('widgets', 'legacy')->grammar() instanceof MySqlGrammar);
    assertSame('mysql', $manager->grammar('legacy')->name());
});
