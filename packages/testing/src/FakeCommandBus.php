<?php

declare(strict_types=1);

namespace LombokClarion\Testing;

use LombokClarion\Bus\CommandBus;
use LombokClarion\Container\Container;

/**
 * Swap the real CommandBus for this in a controller test via
 * $this->override(CommandBus::class, $fake) — see HttpTestCase — to
 * assert "the controller dispatched X" without running any real handler.
 */
final class FakeCommandBus extends CommandBus
{
    /** @var list<object> */
    private array $dispatched = [];

    /** @var array<class-string, mixed> */
    private array $results = [];

    public function __construct()
    {
        parent::__construct(new Container());
    }

    /**
     * @param class-string $commandClass
     */
    public function willReturn(string $commandClass, mixed $result): void
    {
        $this->results[$commandClass] = $result;
    }

    public function dispatch(object $command): mixed
    {
        $this->dispatched[] = $command;
        return $this->results[$command::class] ?? null;
    }

    /** @return list<object> */
    public function dispatchedCommands(): array
    {
        return $this->dispatched;
    }

    public function wasDispatched(string $commandClass): bool
    {
        foreach ($this->dispatched as $command) {
            if ($command instanceof $commandClass) {
                return true;
            }
        }
        return false;
    }
}
