<?php

declare(strict_types=1);

namespace LombokClarion\Bus;

use LombokClarion\Bus\Exceptions\HandlerNotFoundException;
use LombokClarion\Container\ContainerInterface;

/**
 * Controllers are thin — they build a Command and hand it to this bus.
 * Command -> handler is a one-to-one, explicitly registered mapping; there
 * is no attribute scanning of app/Domain for classes that "look like"
 * handlers.
 */
class CommandBus
{
    /** @var array<class-string, class-string> */
    private array $handlers = [];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @param class-string $commandClass
     * @param class-string $handlerClass
     */
    public function register(string $commandClass, string $handlerClass): void
    {
        $this->handlers[$commandClass] = $handlerClass;
    }

    public function dispatch(object $command): mixed
    {
        $commandClass = $command::class;

        if (!isset($this->handlers[$commandClass])) {
            throw HandlerNotFoundException::forCommand($commandClass);
        }

        /** @var CommandHandler $handler */
        $handler = $this->container->get($this->handlers[$commandClass]);

        return $handler->handle($command);
    }

    public function hasHandlerFor(string $commandClass): bool
    {
        return isset($this->handlers[$commandClass]);
    }
}
