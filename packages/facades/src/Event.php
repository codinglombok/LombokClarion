<?php

declare(strict_types=1);

namespace LombokClarion\Facades;

use LombokClarion\Bus\EventBus;

/**
 * @method static void dispatch(object $event)
 */
final class Event extends Facade
{
    protected static function accessor(): string
    {
        return EventBus::class;
    }
}
