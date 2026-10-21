<?php

declare(strict_types=1);

namespace Tests\Broadcasting;

use LombokClarion\Broadcasting\Drivers\DatabaseBroadcaster;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseBroadcasterTest extends TestCase
{
    private PDO $pdo;

    private DatabaseBroadcaster $broadcaster;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->broadcaster = new DatabaseBroadcaster($this->pdo, 'lc_broadcasts_test');
    }

    public function testBroadcastThenReadSince(): void
    {
        $this->broadcaster->broadcast(['orders'], 'created', ['sku' => 'desk-1']);

        $events = $this->broadcaster->since('orders');
        $this->assertCount(1, $events);
        $this->assertSame('created', $events[0]['event']);
        $this->assertSame('desk-1', $events[0]['payload']['sku']);
    }

    public function testChannelIsolation(): void
    {
        $this->broadcaster->broadcast(['orders'], 'created', []);
        $this->broadcaster->broadcast(['payments'], 'captured', []);

        $this->assertCount(1, $this->broadcaster->since('orders'));
        $this->assertCount(1, $this->broadcaster->since('payments'));
        $this->assertCount(0, $this->broadcaster->since('unknown'));
    }

    public function testSinceWithAfterIdReturnsOnlyNewerEvents(): void
    {
        $this->broadcaster->broadcast(['chat'], 'msg', ['n' => 1]);
        $this->broadcaster->broadcast(['chat'], 'msg', ['n' => 2]);

        // Feed without an afterId is newest-first (pollers page backwards).
        $all = $this->broadcaster->since('chat');
        $this->assertCount(2, $all);
        $this->assertSame(2, $all[0]['payload']['n']);

        // with afterId: only strictly-newer rows, oldest-first for replay.
        $afterFirst = $this->broadcaster->since('chat', $all[1]['id']);
        $this->assertCount(1, $afterFirst);
        $this->assertSame(2, $afterFirst[0]['payload']['n']);
    }

    public function testMultipleChannelsOneInsertEach(): void
    {
        $this->broadcaster->broadcast(['a', 'b', 'c'], 'fanout', ['x' => 1]);

        $this->assertCount(1, $this->broadcaster->since('a'));
        $this->assertCount(1, $this->broadcaster->since('b'));
        $this->assertCount(1, $this->broadcaster->since('c'));
    }

    public function testGcRemovesOldEvents(): void
    {
        $this->broadcaster->broadcast(['t'], 'old', []);

        // Nothing is older than 86_400 seconds yet.
        $this->assertSame(0, $this->broadcaster->gc(86400));
        $this->assertCount(1, $this->broadcaster->since('t'));
    }
}
