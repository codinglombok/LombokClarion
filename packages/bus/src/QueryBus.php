<?php

declare(strict_types=1);

namespace LombokClarion\Bus;

use LombokClarion\Bus\Exceptions\HandlerNotFoundException;
use LombokClarion\Container\ContainerInterface;

final class QueryBus
{
    /** @var array<class-string, class-string> */
    private array $handlers = [];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @param class-string $queryClass
     * @param class-string $handlerClass
     */
    public function register(string $queryClass, string $handlerClass): void
    {
        $this->handlers[$queryClass] = $handlerClass;
    }

    public function ask(object $query): mixed
    {
        $queryClass = $query::class;

        if (!isset($this->handlers[$queryClass])) {
            throw HandlerNotFoundException::forQuery($queryClass);
        }

        /** @var QueryHandler $handler */
        $handler = $this->container->get($this->handlers[$queryClass]);

        return $handler->handle($query);
    }

    public function hasHandlerFor(string $queryClass): bool
    {
        return isset($this->handlers[$queryClass]);
    }
}
