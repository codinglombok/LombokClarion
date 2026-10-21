<?php

declare(strict_types=1);

namespace Tests\Framework;

use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\EventBus;
use LombokClarion\Bus\Exceptions\HandlerNotFoundException;
use LombokClarion\Bus\QueryBus;
use LombokClarion\Container\Container;
use PHPUnit\Framework\TestCase;

final class PingCommand
{
    public function __construct(public readonly string $target)
    {
    }
}

final class PingHandler
{
    public function handle(PingCommand $command): string
    {
        return 'pong:' . $command->target;
    }
}

final class FindQuery
{
    public function __construct(public readonly int $id)
    {
    }
}

final class FindHandler
{
    public function handle(FindQuery $query): array
    {
        return ['id' => $query->id];
    }
}

final class OrderPlaced
{
    public function __construct(public readonly string $sku)
    {
    }
}

final class AuditListener
{
    /** @var list<string> */
    public array $seen = [];

    public function handle(OrderPlaced $event): void
    {
        $this->seen[] = 'audit:' . $event->sku;
    }
}

final class MailListener
{
    /** @var list<string> */
    public array $seen = [];

    public function handle(OrderPlaced $event): void
    {
        $this->seen[] = 'mail:' . $event->sku;
    }
}

final class BusTest extends TestCase
{
    public function testCommandBusDispatchesToRegisteredHandler(): void
    {
        $container = new Container();
        $container->singleton(PingHandler::class, PingHandler::class);

        $bus = new CommandBus($container);
        $bus->register(PingCommand::class, PingHandler::class);

        $this->assertSame('pong:world', $bus->dispatch(new PingCommand('world')));
    }

    public function testCommandBusRejectsUnregisteredCommands(): void
    {
        $bus = new CommandBus(new Container());

        $this->expectException(HandlerNotFoundException::class);
        $bus->dispatch(new PingCommand('nobody'));
    }

    public function testCommandBusHasHandlerFor(): void
    {
        $container = new Container();
        $container->singleton(PingHandler::class, PingHandler::class);

        $bus = new CommandBus($container);
        $bus->register(PingCommand::class, PingHandler::class);

        $this->assertTrue($bus->hasHandlerFor(PingCommand::class));
        $this->assertFalse($bus->hasHandlerFor(FindQuery::class));
    }

    public function testQueryBusAskReturnsResult(): void
    {
        $container = new Container();
        $container->singleton(FindHandler::class, FindHandler::class);

        $bus = new QueryBus($container);
        $bus->register(FindQuery::class, FindHandler::class);

        $this->assertSame(['id' => 7], $bus->ask(new FindQuery(7)));
    }

    public function testEventBusNotifiesAllListenersInOrder(): void
    {
        $container = new Container();
        $audit = new AuditListener();
        $mail = new MailListener();
        $container->instance(AuditListener::class, $audit);
        $container->instance(MailListener::class, $mail);

        $bus = new EventBus($container);
        $bus->listen(OrderPlaced::class, AuditListener::class);
        $bus->listen(OrderPlaced::class, MailListener::class);

        $bus->dispatch(new OrderPlaced('desk-1'));

        $this->assertSame(['audit:desk-1'], $audit->seen);
        $this->assertSame(['mail:desk-1'], $mail->seen);
        $this->assertSame([AuditListener::class, MailListener::class], $bus->listenersFor(OrderPlaced::class));
    }

    public function testEventBusIgnoresEventsWithoutListeners(): void
    {
        $bus = new EventBus(new Container());

        // No listeners registered — dispatch is a no-op, not an error.
        $bus->dispatch(new OrderPlaced('quiet'));
        $this->assertSame([], $bus->listenersFor(OrderPlaced::class));
    }
}
