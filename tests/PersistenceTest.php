<?php

declare(strict_types=1);

use LombokClarion\Persistence\Exceptions\QueryException;
use LombokClarion\Persistence\Migration;
use LombokClarion\Persistence\MigrationRunner;
use LombokClarion\Persistence\QueryBuilder;
use LombokClarion\Persistence\RawExpression;
use LombokClarion\Persistence\SchemaBuilder;
use LombokClarion\Persistence\TrustedDdl;

function test_make_sqlite(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, price INTEGER NOT NULL)');
    return $pdo;
}

test('insert then get returns the row', function () {
    $pdo = test_make_sqlite();
    $qb = new QueryBuilder($pdo, 'widgets');
    $id = $qb->insert(['name' => 'Lamp', 'price' => 1200]);
    assertSame('1', $id);

    $rows = $qb->get();
    assertSame(1, count($rows));
    assertSame('Lamp', $rows[0]['name']);
});

test('where with bound parameter filters rows, immune to injection payloads', function () {
    $pdo = test_make_sqlite();
    $qb = new QueryBuilder($pdo, 'widgets');
    $qb->insert(['name' => "Robert'); DROP TABLE widgets;--", 'price' => 5]);
    $qb->insert(['name' => 'Chair', 'price' => 5]);

    $found = $qb->where('name', '=', "Robert'); DROP TABLE widgets;--")->get();
    assertSame(1, count($found));

    // table must still exist — no injection occurred
    $all = (new QueryBuilder($pdo, 'widgets'))->get();
    assertSame(2, count($all));
});

test('where in with array value', function () {
    $pdo = test_make_sqlite();
    $qb = new QueryBuilder($pdo, 'widgets');
    $qb->insert(['name' => 'A', 'price' => 1]);
    $qb->insert(['name' => 'B', 'price' => 2]);
    $qb->insert(['name' => 'C', 'price' => 3]);

    $rows = $qb->where('name', 'in', ['A', 'C'])->orderBy('name')->get();
    assertSame(2, count($rows));
    assertSame('A', $rows[0]['name']);
    assertSame('C', $rows[1]['name']);
});

test('invalid identifier is rejected', function () {
    $pdo = test_make_sqlite();
    assertThrows(QueryException::class, function () use ($pdo) {
        (new QueryBuilder($pdo, 'widgets'))->where('name; DROP TABLE widgets;--', '=', 'x');
    });
});

test('update requires a where clause', function () {
    $pdo = test_make_sqlite();
    $qb = new QueryBuilder($pdo, 'widgets');
    $qb->insert(['name' => 'A', 'price' => 1]);
    assertThrows(QueryException::class, fn () => $qb->update(['price' => 99]));
});

test('update with where clause mutates only matching rows', function () {
    $pdo = test_make_sqlite();
    $qb = new QueryBuilder($pdo, 'widgets');
    $qb->insert(['name' => 'A', 'price' => 1]);
    $qb->insert(['name' => 'B', 'price' => 1]);

    $affected = $qb->where('name', '=', 'A')->update(['price' => 999]);
    assertSame(1, $affected);

    $rows = (new QueryBuilder($pdo, 'widgets'))->orderBy('name')->get();
    assertSame(999, $rows[0]['price']);
    assertSame(1, $rows[1]['price']);
});

test('delete requires a where clause', function () {
    $pdo = test_make_sqlite();
    $qb = new QueryBuilder($pdo, 'widgets');
    assertThrows(QueryException::class, fn () => $qb->delete());
});

test('rawExpression rejects placeholder/binding count mismatch', function () {
    assertThrows(QueryException::class, fn () => new RawExpression('price > ? AND price < ?', [10]));
});

test('rawExpression works via whereRaw', function () {
    $pdo = test_make_sqlite();
    $qb = new QueryBuilder($pdo, 'widgets');
    $qb->insert(['name' => 'A', 'price' => 5]);
    $qb->insert(['name' => 'B', 'price' => 50]);

    $rows = $qb->whereRaw(new RawExpression('"price" > ?', [10]))->get();
    assertSame(1, count($rows));
    assertSame('B', $rows[0]['name']);
});

final class Test_CreateOrdersTable implements Migration
{
    public function up(SchemaBuilder $schema): void
    {
        $schema->createTable('orders', [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'total' => 'INTEGER NOT NULL',
        ]);
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropTable('orders');
    }

    public function runsInTransaction(SchemaBuilder $schema): bool
    {
        return $schema->migrationsAreTransactionalByDefault();
    }
}

test('migration runner applies migrations from an explicit manifest exactly once', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = new SchemaBuilder($pdo, 'sqlite');
    $runner = new MigrationRunner($pdo, $schema, 'sqlite');

    $ran = $runner->migrate([Test_CreateOrdersTable::class]);
    assertSame([Test_CreateOrdersTable::class], $ran);

    // Second run should be a no-op (already applied).
    $ranAgain = $runner->migrate([Test_CreateOrdersTable::class]);
    assertSame([], $ranAgain);

    $qb = new QueryBuilder($pdo, 'orders');
    $qb->insert(['total' => 100]);
    assertSame(1, count($qb->get()));
});

function test_make_join_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, category_id INTEGER NOT NULL, price INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO categories VALUES (1, 'Electronics')");
    $pdo->exec("INSERT INTO categories VALUES (2, 'Furniture')");
    $pdo->exec("INSERT INTO products VALUES (1, 'Laptop', 1, 999)");
    $pdo->exec("INSERT INTO products VALUES (2, 'Phone', 1, 799)");
    $pdo->exec("INSERT INTO products VALUES (3, 'Desk', 2, 200)");
    return $pdo;
}

test('join() produces a correct INNER JOIN with validated identifiers', function () {
    $pdo = test_make_join_db();
    $rows = (new QueryBuilder($pdo, 'products'))
        ->select('products.name', 'categories.name')
        ->join('categories', 'products.category_id', '=', 'categories.id')
        ->where('categories.name', '=', 'Electronics')
        ->orderBy('products.name')
        ->get();

    assertSame(2, count($rows));
});

test('leftJoin() includes rows with no match in the joined table', function () {
    $pdo = test_make_join_db();
    $pdo->exec("INSERT INTO categories VALUES (3, 'Empty')");

    // Use rawExpression for aliased select since QB doesn't have alias
    // support — the point of this test is the LEFT JOIN itself.
    $stmt = $pdo->prepare(
        'SELECT "categories"."id", "categories"."name" as cat_name, "products"."name" as prod_name ' .
        'FROM "categories" LEFT JOIN "products" ON "categories"."id" = "products"."category_id" ' .
        'ORDER BY "categories"."name"'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Now verify our QB generates the same join structure.
    $qbRows = (new QueryBuilder($pdo, 'categories'))
        ->leftJoin('products', 'categories.id', '=', 'products.category_id')
        ->orderBy('categories.name')
        ->get();

    // "Empty" category has null product, but should still appear.
    $catNames = array_unique(array_column($qbRows, 'id'));
    assertTrue(in_array(3, $catNames, true), 'Empty category (id=3) must appear in LEFT JOIN results');
});

test('groupBy + count works for aggregate queries', function () {
    $pdo = test_make_join_db();
    $rows = (new QueryBuilder($pdo, 'products'))
        ->select('category_id')
        ->groupBy('category_id')
        ->orderBy('category_id')
        ->get();

    assertSame(2, count($rows)); // 2 distinct categories
});

test('join rejects invalid table/column identifiers (no injection surface)', function () {
    $pdo = test_make_join_db();
    assertThrows(QueryException::class, function () use ($pdo) {
        (new QueryBuilder($pdo, 'products'))->join('evil;--', 'a', '=', 'b');
    });
});

// --- TrustedDdl: a marker with teeth ---------------------------------------
// TrustedDdl::mark() must return a bare string (PDO::exec() rejects Stringable
// under strict_types), which would make it a universal "silence the audit"
// bypass if it accepted anything. These tests pin the narrowing that prevents
// that: DDL passes, anything carrying values does not.

test('TrustedDdl::mark returns DDL unchanged', function () {
    $sql = 'CREATE TABLE t (id INTEGER PRIMARY KEY)';
    assertSame($sql, TrustedDdl::mark($sql));
});

test('TrustedDdl::mark accepts every DDL verb regardless of leading whitespace', function () {
    assertSame('  ALTER TABLE t ADD COLUMN c TEXT', TrustedDdl::mark('  ALTER TABLE t ADD COLUMN c TEXT'));
    assertSame('drop table t', TrustedDdl::mark('drop table t'));
    assertSame("\n TRUNCATE TABLE t", TrustedDdl::mark("\n TRUNCATE TABLE t"));
});

test('TrustedDdl::mark refuses to launder a SELECT carrying a value', function () {
    assertThrows(QueryException::class, fn () => TrustedDdl::mark("SELECT * FROM users WHERE id = 5 OR 1=1"));
});

test('TrustedDdl::mark refuses DML', function () {
    assertThrows(QueryException::class, fn () => TrustedDdl::mark('DELETE FROM users'));
    assertThrows(QueryException::class, fn () => TrustedDdl::mark('INSERT INTO users VALUES (1)'));
    assertThrows(QueryException::class, fn () => TrustedDdl::mark('UPDATE users SET admin = 1'));
});

test('SchemaBuilder still creates and drops tables through the marked path', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = new SchemaBuilder($pdo, 'sqlite');
    $schema->createTable('things', ['id' => 'INTEGER PRIMARY KEY', 'name' => 'TEXT NOT NULL']);
    $pdo->exec("INSERT INTO things (name) VALUES ('x')");
    assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM things')->fetchColumn());
    $schema->dropTable('things');
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='things'")->fetchAll();
    assertSame([], $tables);
});

test('SchemaBuilder still rejects an invalid identifier', function () {
    $pdo = new PDO('sqlite::memory:');
    $schema = new SchemaBuilder($pdo, 'sqlite');
    assertThrows(QueryException::class, fn () => $schema->createTable('bad name; DROP TABLE x', ['id' => 'INTEGER']));
});

// --- Stage 11: paginate() ------------------------------------------------

function test_paginate_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, category TEXT)');
    $stmt = $pdo->prepare('INSERT INTO items (name, category) VALUES (?, ?)');
    for ($i = 1; $i <= 23; $i++) {
        $stmt->execute(["item$i", $i <= 20 ? 'a' : 'b']);
    }
    return $pdo;
}

test('paginate returns one page plus meta, and the last page is short', function () {
    $pdo = test_paginate_db();

    $page1 = (new QueryBuilder($pdo, 'items'))->orderBy('id')->paginate(1, 10);
    assertSame(10, count($page1['data']));
    assertSame('item1', $page1['data'][0]['name']);
    assertSame(
        ['page' => 1, 'per_page' => 10, 'total' => 23, 'last_page' => 3, 'from' => 1, 'to' => 10],
        $page1['meta']
    );

    $page3 = (new QueryBuilder($pdo, 'items'))->orderBy('id')->paginate(3, 10);
    assertSame(3, count($page3['data']));
    assertSame('item21', $page3['data'][0]['name']);
    assertSame(21, $page3['meta']['from']);
    assertSame(23, $page3['meta']['to']);
});

test('paginate total respects wheres — count and page cannot disagree', function () {
    $pdo = test_paginate_db();

    $result = (new QueryBuilder($pdo, 'items'))
        ->where('category', '=', 'a')
        ->orderBy('id')
        ->paginate(2, 15);

    // 20 category-a rows: page 2 of 15 holds the last 5, and total is the
    // FILTERED count, not the table count.
    assertSame(5, count($result['data']));
    assertSame(20, $result['meta']['total']);
    assertSame(2, $result['meta']['last_page']);
});

test('paginate clamps user-shaped page input but throws on code-shaped per_page', function () {
    $pdo = test_paginate_db();

    // ?page=0 and ?page=-3 from a hand-edited URL mean "first page", not 500.
    $clamped = (new QueryBuilder($pdo, 'items'))->orderBy('id')->paginate(0, 10);
    assertSame(1, $clamped['meta']['page']);
    assertSame('item1', $clamped['data'][0]['name']);

    // per_page comes from code; per_page <= 0 is a bug to surface.
    assertThrows(QueryException::class, fn () => (new QueryBuilder($pdo, 'items'))->paginate(1, 0));
});

test('paginate past the end returns an empty page with honest meta', function () {
    $pdo = test_paginate_db();

    $result = (new QueryBuilder($pdo, 'items'))->orderBy('id')->paginate(99, 10);
    assertSame([], $result['data']);
    assertSame(99, $result['meta']['page']);
    assertSame(3, $result['meta']['last_page']);
    assertSame(null, $result['meta']['from']);
    assertSame(null, $result['meta']['to']);
});

test('paginate on an empty result set: total 0, last_page 1, from/to null', function () {
    $pdo = test_paginate_db();

    $result = (new QueryBuilder($pdo, 'items'))->where('category', '=', 'zzz')->paginate(1, 10);
    assertSame([], $result['data']);
    assertSame(0, $result['meta']['total']);
    assertSame(1, $result['meta']['last_page']);
    assertSame(null, $result['meta']['from']);
});

// --- #45: rollback — the down() path is finally executable -----------------

final class Test_RbA implements Migration
{
    public function up(SchemaBuilder $schema): void { $schema->createTable('rb_a', ['id' => 'INTEGER PRIMARY KEY']); }
    public function down(SchemaBuilder $schema): void { $schema->dropTable('rb_a'); }
    public function runsInTransaction(SchemaBuilder $schema): bool { return true; }
}

final class Test_RbB implements Migration
{
    public function up(SchemaBuilder $schema): void { $schema->createTable('rb_b', ['id' => 'INTEGER PRIMARY KEY']); }
    public function down(SchemaBuilder $schema): void { $schema->dropTable('rb_b'); }
    public function runsInTransaction(SchemaBuilder $schema): bool { return true; }
}

function test_rbRunner(PDO $pdo): MigrationRunner
{
    return new MigrationRunner($pdo, new SchemaBuilder($pdo, 'sqlite'), 'sqlite');
}

test('rollback undoes the newest migration first and un-records it', function () {
    $pdo = new PDO('sqlite::memory:');
    $runner = test_rbRunner($pdo);
    $manifest = [Test_RbA::class, Test_RbB::class];
    $runner->migrate($manifest);

    $rolled = $runner->rollback($manifest, 1);

    assertSame([Test_RbB::class], $rolled);
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'rb_%'")->fetchAll(PDO::FETCH_COLUMN);
    assertSame(['rb_a'], $tables);
    assertSame([Test_RbA::class], $runner->appliedMigrations());

    // And migrate() re-applies exactly what was rolled back — the round trip.
    assertSame([Test_RbB::class], $runner->migrate($manifest));
});

test('rollback --steps walks multiple, and over-asking stops at zero without error', function () {
    $pdo = new PDO('sqlite::memory:');
    $runner = test_rbRunner($pdo);
    $manifest = [Test_RbA::class, Test_RbB::class];
    $runner->migrate($manifest);

    // steps=5 with 2 applied: rolls back both, complains about nothing —
    // "undo everything" should not require counting first.
    assertSame([Test_RbB::class, Test_RbA::class], $runner->rollback($manifest, 5));
    assertSame([], $runner->appliedMigrations());
    assertSame([], $runner->rollback($manifest, 1));
});

test('rollback refuses an applied migration whose class left the manifest', function () {
    $pdo = new PDO('sqlite::memory:');
    $runner = test_rbRunner($pdo);
    $runner->migrate([Test_RbA::class, Test_RbB::class]);

    // The class is gone from the manifest (deleted file, renamed class):
    // guessing is not a rollback, so this is a hard error naming the options.
    assertThrows(RuntimeException::class, fn () => $runner->rollback([Test_RbA::class], 1));

    // And nothing was silently half-done.
    assertSame([Test_RbA::class, Test_RbB::class], $runner->appliedMigrations());
});

// --- #46: whereNull / whereNotNull (dari konsumen pertama, SIGAP-MP) -------

test('whereNull/whereNotNull express IS NULL — the thing no bound parameter can say', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE rows (id INTEGER PRIMARY KEY, deleted_at TEXT)');
    $pdo->exec("INSERT INTO rows (id, deleted_at) VALUES (1, NULL), (2, '2026-01-01'), (3, NULL)");

    $alive = (new QueryBuilder($pdo, 'rows'))->whereNull('deleted_at')->orderBy('id')->get();
    assertSame([1, 3], array_map(fn ($r) => (int) $r['id'], $alive));

    $dead = (new QueryBuilder($pdo, 'rows'))->whereNotNull('deleted_at')->get();
    assertSame([2], array_map(fn ($r) => (int) $r['id'], $dead));

    // Composes with bound-parameter wheres, and the generated SQL carries
    // no placeholder for the NULL side.
    [$sql, $bindings] = (new QueryBuilder($pdo, 'rows'))
        ->whereNull('deleted_at')->where('id', '>', 1)->toSql();
    assertSame('SELECT * FROM "rows" WHERE "deleted_at" IS NULL AND "id" > ?', $sql);
    assertSame([1], $bindings);

    // 'is' as a where() operator stays rejected — the method IS the API.
    assertThrows(QueryException::class, fn () => (new QueryBuilder($pdo, 'rows'))->where('deleted_at', 'IS', null));
});
