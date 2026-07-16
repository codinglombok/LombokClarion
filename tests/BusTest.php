<?php

declare(strict_types=1);

use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\CommandHandler;
use LombokClarion\Bus\EventBus;
use LombokClarion\Bus\EventListener;
use LombokClarion\Bus\Exceptions\HandlerNotFoundException;
use LombokClarion\Bus\QueryBus;
use LombokClarion\Bus\QueryHandler;
use LombokClarion\Container\Container;

final class Test_CreateWidget
{
    public function __construct(public readonly string $name)
    {
    }
}

final class Test_CreateWidgetHandler implements CommandHandler
{
    public array $created = [];

    public function handle(object $command): mixed
    {
        $this->created[] = $command->name;
        return 'widget-1';
    }
}

final class Test_GetWidgetCount
{
}

final class Test_GetWidgetCountHandler implements QueryHandler
{
    public function handle(object $query): mixed
    {
        return 3;
    }
}

final class Test_WidgetCreated
{
    public function __construct(public readonly string $name)
    {
    }
}

final class Test_LogWidgetCreatedListener implements EventListener
{
    public static array $seen = [];

    public function handle(object $event): void
    {
        self::$seen[] = $event->name;
    }
}

test('command bus dispatches to the registered handler', function () {
    $container = new Container();
    $handler = new Test_CreateWidgetHandler();
    $container->instance(Test_CreateWidgetHandler::class, $handler);

    $bus = new CommandBus($container);
    $bus->register(Test_CreateWidget::class, Test_CreateWidgetHandler::class);

    $result = $bus->dispatch(new Test_CreateWidget('lamp'));
    assertSame('widget-1', $result);
    assertSame(['lamp'], $handler->created);
});

test('command bus throws when no handler is registered', function () {
    $bus = new CommandBus(new Container());
    assertThrows(HandlerNotFoundException::class, fn () => $bus->dispatch(new Test_CreateWidget('lamp')));
});

test('query bus asks the registered handler', function () {
    $container = new Container();
    $bus = new QueryBus($container);
    $bus->register(Test_GetWidgetCount::class, Test_GetWidgetCountHandler::class);
    assertSame(3, $bus->ask(new Test_GetWidgetCount()));
});

test('event bus dispatches to all registered listeners in order', function () {
    Test_LogWidgetCreatedListener::$seen = [];
    $container = new Container();
    $bus = new EventBus($container);
    $bus->listen(Test_WidgetCreated::class, Test_LogWidgetCreatedListener::class);

    $bus->dispatch(new Test_WidgetCreated('lamp'));
    assertSame(['lamp'], Test_LogWidgetCreatedListener::$seen);
});

test('event bus does nothing when no listeners are registered', function () {
    $bus = new EventBus(new Container());
    $bus->dispatch(new Test_WidgetCreated('lamp')); // must not throw
    assertTrue(true);
});
