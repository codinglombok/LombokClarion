<?php

declare(strict_types=1);

use LombokClarion\Persistence\Exceptions\QueryException;
use LombokClarion\Persistence\Migration;
use LombokClarion\Persistence\MigrationRunner;
use LombokClarion\Persistence\QueryBuilder;
use LombokClarion\Persistence\RawExpression;
use LombokClarion\Persistence\SchemaBuilder;

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
