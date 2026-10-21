<?php

declare(strict_types=1);

namespace Tests\Session;

use LombokClarion\Session\Handlers\ArraySessionHandler;
use LombokClarion\Session\Handlers\DatabaseSessionHandler;
use LombokClarion\Persistence\Exceptions\QueryException;
use PDO;
use PHPUnit\Framework\TestCase;

final class SessionHandlersTest extends TestCase
{
    public function testArrayHandlerRoundTrip(): void
    {
        $handler = new ArraySessionHandler();

        $this->assertNull($handler->read('sess-1'));

        $handler->write('sess-1', 'cart=apple', 3600);
        $this->assertSame('cart=apple', $handler->read('sess-1'));

        $handler->destroy('sess-1');
        $this->assertNull($handler->read('sess-1'));
    }

    public function testArrayHandlerGcRemovesExpiredOnly(): void
    {
        $handler = new ArraySessionHandler();

        $handler->write('old', 'a', -1);  // already expired at write time
        $handler->write('fresh', 'b', 3600);

        $removed = $handler->gc(0);
        $this->assertSame(1, $removed);
        $this->assertNull($handler->read('old'));
        $this->assertSame('b', $handler->read('fresh'));
    }

    public function testDatabaseHandlerRoundTrip(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $handler = new DatabaseSessionHandler($pdo, 'lc_sessions_test');

        $this->assertNull($handler->read('sess-1'));

        $handler->write('sess-1', 'user=42', 3600);
        $this->assertSame('user=42', $handler->read('sess-1'));

        $handler->write('sess-1', 'user=43', 3600); // upsert
        $this->assertSame('user=43', $handler->read('sess-1'));

        $handler->destroy('sess-1');
        $this->assertNull($handler->read('sess-1'));
    }

    public function testDatabaseHandlerGcRemovesExpired(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $handler = new DatabaseSessionHandler($pdo);

        $handler->write('expired', 'a', -1000);
        $handler->write('living', 'b', 3600);

        $this->assertSame(1, $handler->gc(0));
        $this->assertSame('b', $handler->read('living'));
    }
    public function testDatabaseHandlerRejectsUnsafeTableName(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(QueryException::class);
        new DatabaseSessionHandler($pdo, 'sessions" --');
    }
}
