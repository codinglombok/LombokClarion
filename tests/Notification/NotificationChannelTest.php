<?php

declare(strict_types=1);

namespace Tests\Notification;

use LombokClarion\Notification\Channels\DatabaseChannel;
use LombokClarion\Notification\Notifiable;
use LombokClarion\Notification\Notification;
use PDO;
use PHPUnit\Framework\TestCase;

final class InvoicePaidNotification extends Notification
{
    public function via(Notifiable $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(Notifiable $notifiable): array
    {
        return ['invoice' => 'INV-1', 'amount' => 250000];
    }
}

final class Customer implements Notifiable
{
    public function __construct(private readonly int|string $id)
    {
    }

    public function notifiableId(): string|int
    {
        return $this->id;
    }

    public function routeNotificationFor(string $channel): mixed
    {
        return null;
    }
}

final class NotificationChannelTest extends TestCase
{
    private PDO $pdo;

    private DatabaseChannel $channel;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->channel = new DatabaseChannel($this->pdo, 'lc_notifications_test');
    }

    public function testChannelIsNamedDatabase(): void
    {
        $this->assertSame('database', $this->channel->name());
    }

    public function testSendStoresJsonPayload(): void
    {
        $this->channel->send(new Customer(7), new InvoicePaidNotification());

        $rows = $this->channel->unread(new Customer(7));
        $this->assertCount(1, $rows);
        $this->assertSame('INV-1', $rows[0]['data']['invoice']);
        $this->assertSame('250000', (string) $rows[0]['data']['amount']);
        $this->assertSame(InvoicePaidNotification::class, $rows[0]['type']);
    }

    public function testUnreadIsFilteredPerNotifiable(): void
    {
        $this->channel->send(new Customer(1), new InvoicePaidNotification());
        $this->channel->send(new Customer(2), new InvoicePaidNotification());

        $this->assertCount(1, $this->channel->unread(new Customer(1)));
        $this->assertCount(1, $this->channel->unread(new Customer(2)));
    }

    public function testMarkAsReadFlipsUnreadState(): void
    {
        $customer = new Customer(9);
        $this->channel->send($customer, new InvoicePaidNotification());

        $rows = $this->channel->unread($customer);
        $this->channel->markAsRead($rows[0]['id']);

        $this->assertCount(0, $this->channel->unread($customer));
    }

    public function testMarkAllAsRead(): void
    {
        $customer = new Customer(3);
        $this->channel->send($customer, new InvoicePaidNotification());
        $this->channel->send($customer, new InvoicePaidNotification());

        $this->channel->markAllAsRead($customer);

        $this->assertCount(0, $this->channel->unread($customer));
    }

    public function testSendWithoutToDatabaseMethodIsRejected(): void
    {
        $bare = new class() extends Notification {
            public function via(Notifiable $notifiable): array
            {
                return ['database'];
            }
        };

        $this->expectException(\BadMethodCallException::class);
        $this->channel->send(new Customer(1), $bare);
    }
}
