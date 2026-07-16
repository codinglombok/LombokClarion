<?php

declare(strict_types=1);

namespace LombokClarion\Testing;

use LombokClarion\Bus\EventBus;
use LombokClarion\Container\Container;

final class FakeEventBus extends EventBus
{
    /** @var list<object> */
    private array $dispatched = [];

    public function __construct()
    {
        parent::__construct(new Container());
    }

    public function dispatch(object $event): void
    {
        $this->dispatched[] = $event;
    }

    /** @return list<object> */
    public function dispatchedEvents(): array
    {
        return $this->dispatched;
    }

    public function wasDispatched(string $eventClass): bool
    {
        foreach ($this->dispatched as $event) {
            if ($event instanceof $eventClass) {
                return true;
            }
        }
        return false;
    }
}
