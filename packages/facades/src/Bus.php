<?php

declare(strict_types=1);

namespace LombokClarion\Facades;

use LombokClarion\Bus\CommandBus;

/**
 * @method static mixed dispatch(object $command)
 */
final class Bus extends Facade
{
    protected static function accessor(): string
    {
        return CommandBus::class;
    }
}
