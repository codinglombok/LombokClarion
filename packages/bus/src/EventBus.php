<?php

declare(strict_types=1);

namespace LombokClarion\Bus;

use LombokClarion\Container\ContainerInterface;

class EventBus
{
    /** @var array<class-string, list<class-string>> */
    private array $listeners = [];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @param class-string $eventClass
     * @param class-string $listenerClass
     */
    public function listen(string $eventClass, string $listenerClass): void
    {
        $this->listeners[$eventClass][] = $listenerClass;
    }

    public function dispatch(object $event): void
    {
        foreach ($this->listeners[$event::class] ?? [] as $listenerClass) {
            /** @var EventListener $listener */
            $listener = $this->container->get($listenerClass);
            $listener->handle($event);
        }
    }

    /** @return list<class-string> */
    public function listenersFor(string $eventClass): array
    {
        return $this->listeners[$eventClass] ?? [];
    }
}
