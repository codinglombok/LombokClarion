<?php

declare(strict_types=1);

namespace LombokClarion\Bus\Exceptions;

use RuntimeException;

final class HandlerNotFoundException extends RuntimeException
{
    public static function forCommand(string $commandClass): self
    {
        return new self(sprintf(
            'No handler registered for "%s". Register it explicitly: ' .
            '$commandBus->register(%s::class, YourHandler::class) in bootstrap/services.php.',
            $commandClass,
            $commandClass
        ));
    }

    public static function forQuery(string $queryClass): self
    {
        return new self(sprintf(
            'No handler registered for "%s". Register it explicitly: ' .
            '$queryBus->register(%s::class, YourHandler::class) in bootstrap/services.php.',
            $queryClass,
            $queryClass
        ));
    }
}
