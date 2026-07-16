<?php

declare(strict_types=1);

namespace LombokClarion\Bus;

/**
 * @template TCommand of object
 */
interface CommandHandler
{
    /**
     * @param TCommand $command
     */
    public function handle(object $command): mixed;
}
