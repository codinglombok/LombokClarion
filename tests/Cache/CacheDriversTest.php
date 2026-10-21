<?php

declare(strict_types=1);

namespace Tests\Cache;

use LombokClarion\Cache\Drivers\ArrayCacheDriver;
use LombokClarion\Cache\Drivers\DatabaseCacheDriver;
use LombokClarion\Cache\Drivers\NullCacheDriver;
use LombokClarion\Persistence\Exceptions\QueryException;
use PDO;
use PHPUnit\Framework\TestCase;

final class CacheDriversTest extends TestCase
{
    public function testArrayDriverRoundTrip(): void
    {
        $cache = new ArrayCacheDriver();
        $cache->put('a', ['x' => 1], 60);

        $this->assertSame(['x' => 1], $cache->get('a'));
        $this->assertTrue($cache->has('a'));
        $this->assertFalse($cache->has('missing'));
    }

    public function testArrayDriverForgetAndFlush(): void
    {
        $cache = new ArrayCacheDriver();
        $cache->put('a', 1, 60);
        $cache->put('b', 2, 60);

        $cache->forget('a');
        $this->assertNull($cache->get('a'));
        $this->assertSame(2, $cache->get('b'));

        $cache->flush();
        $this->assertNull($cache->get('b'));
    }

    public function testRememberComputesOnce(): void
    {
        $cache = new ArrayCacheDriver();
        $calls = 0;

        $factory = static function () use (&$calls): int {
            return ++$calls;
        };

        $this->assertSame(1, $cache->remember('k', 60, $factory));
        $this->assertSame(1, $cache->remember('k', 60, $factory));
        $this->assertSame(1, $calls);
    }

    public function testIncrementAndDecrement(): void
    {
        $cache = new ArrayCacheDriver();

        $this->assertSame(1, $cache->increment('hits'));
        $this->assertSame(3, $cache->increment('hits', 2));
        $this->assertSame(2, $cache->decrement('hits'));
    }

    public function testNullDriverAlwaysMisses(): void
    {
        $cache = new NullCacheDriver();
        $cache->put('x', 1, 60);

        $this->assertNull($cache->get('x'));
        $this->assertFalse($cache->has('x'));
    }

    public function testDatabaseDriverRoundTrip(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cache = new DatabaseCacheDriver($pdo, 'lc_cache_test');

        $cache->put('greeting', ['hello' => 'world'], 60);

        $this->assertSame(['hello' => 'world'], $cache->get('greeting'));
        $this->assertTrue($cache->has('greeting'));
    }

    public function testDatabaseDriverUpsertOverwrites(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cache = new DatabaseCacheDriver($pdo);

        $cache->put('k', 'v1', 60);
        $cache->put('k', 'v2', 60);

        $this->assertSame('v2', $cache->get('k'));
    }

    public function testDatabaseDriverForgetAndGc(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cache = new DatabaseCacheDriver($pdo);

        $cache->put('gone', 'x', 60);
        $cache->forget('gone');
        $this->assertNull($cache->get('gone'));

        $cache->put('expired', 'y', -1); // already expired
        $this->assertNull($cache->get('expired'));
        $this->assertSame(0, $cache->gc()); // gc() above already purged it
    }

    public function testDatabaseDriverRejectsUnsafeTableName(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(QueryException::class);
        new DatabaseCacheDriver($pdo, 'cache; DROP TABLE users');
    }
}
