<?php

declare(strict_types=1);

namespace Tests\Framework;

use LombokClarion\Persistence\ConnectionConfig;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Persistence\Exceptions\QueryException;
use LombokClarion\Persistence\Identifier;
use LombokClarion\Persistence\QueryBuilder;
use LombokClarion\Persistence\TrustedDdl;
use PDO;
use PHPUnit\Framework\TestCase;

final class PersistenceTest extends TestCase
{
    private PDO $pdo;
    private ConnectionManager $manager;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->manager = new ConnectionManager([
            'default' => ConnectionConfig::provided('sqlite', $this->pdo),
        ]);
    }

    private function createItemsTable(): void
    {
        $this->pdo->exec(TrustedDdl::mark(
            'CREATE TABLE items ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name VARCHAR(255) NOT NULL, '
            . 'price_cents INTEGER NOT NULL'
            . ')'
        ));

        $insert = $this->pdo->prepare('INSERT INTO items (name, price_cents) VALUES (?, ?)');
        $insert->execute(['desk', 1000]);
        $insert->execute(['chair', 500]);
        $insert->execute(['lamp', 250]);
    }

    public function testInsertAndGetRoundTrip(): void
    {
        $this->createItemsTable();

        $id = $this->manager->table('items')->insert(['name' => 'shelf', 'price_cents' => 750]);
        $rows = $this->manager->table('items')->where('name', '=', 'shelf')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('shelf', $rows[0]['name']);
        $this->assertSame('750', (string) $rows[0]['price_cents']); // sqlite returns strings
        $this->assertTrue((int) $id > 0);
    }

    public function testWhereInAndOrderBy(): void
    {
        $this->createItemsTable();

        $rows = $this->manager->table('items')
            ->where('name', 'in', ['desk', 'lamp'])
            ->orderBy('price_cents', 'desc')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('desk', $rows[0]['name']);
    }

    public function testUpdateRequiresWhereClause(): void
    {
        $this->createItemsTable();

        $this->expectException(QueryException::class);
        $this->manager->table('items')->update(['price_cents' => 1]);
    }

    public function testDeleteRequiresWhereClause(): void
    {
        $this->createItemsTable();

        $this->expectException(QueryException::class);
        $this->manager->table('items')->delete();
    }

    public function testUpdateAndCount(): void
    {
        $this->createItemsTable();

        $affected = $this->manager->table('items')->where('name', '=', 'lamp')->update(['price_cents' => 999]);
        $this->assertSame(1, $affected);
        $this->assertSame(3, $this->manager->table('items')->count());
    }

    public function testToSqlBindsValuesNeverInterpolates(): void
    {
        $this->createItemsTable();

        [$sql, $bindings] = $this->manager->table('items')
            ->where('price_cents', '>', 300)
            ->toSql();

        $this->assertStringNotContainsString('300', $sql);
        $this->assertContains(300, $bindings);
        $this->assertStringContainsString('WHERE', $sql);
    }

    public function testPaginateReturnsMeta(): void
    {
        $this->createItemsTable();

        $page = $this->manager->table('items')->orderBy('id')->paginate(2, 2);

        $this->assertCount(1, $page['data']);
        $this->assertSame(2, $page['meta']['page']);
        $this->assertSame(3, $page['meta']['total']);
        $this->assertSame(2, $page['meta']['last_page']);
    }

    public function testBuilderIsImmutable(): void
    {
        $this->createItemsTable();

        $base = $this->manager->table('items');
        $filtered = $base->where('name', '=', 'desk');

        $this->assertSame(3, count($base->get()));
        $this->assertSame(1, count($filtered->get()));
    }

    public function testIdentifierRejectsNonIdentifiers(): void
    {
        $this->assertSame('users', Identifier::validate('users'));
        $this->assertSame('"users"', Identifier::quote('users'));

        $this->expectException(QueryException::class);
        Identifier::validate('users; DROP TABLE users');
    }

    public function testIdentifierRejectsEmptyAndHyphenated(): void
    {
        $this->expectException(QueryException::class);
        Identifier::validate('');
    }

    public function testTrustedDdlAcceptsDdlVerbsOnly(): void
    {
        $this->assertSame('CREATE TABLE t (id int)', TrustedDdl::mark('CREATE TABLE t (id int)'));
        $this->assertSame('ALTER TABLE t ADD c int', TrustedDdl::mark('ALTER TABLE t ADD c int'));

        $this->expectException(QueryException::class);
        TrustedDdl::mark('SELECT * FROM t');
    }

    public function testTrustedDdlRejectsValueStatements(): void
    {
        $this->expectException(QueryException::class);
        TrustedDdl::mark('DELETE FROM t WHERE id = 1');
    }
}
