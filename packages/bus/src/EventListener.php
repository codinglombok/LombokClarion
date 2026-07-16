<?php

declare(strict_types=1);

namespace LombokClarion\Bus;

/**
 * @template TEvent of object
 */
interface EventListener
{
    /**
     * @param TEvent $event
     */
    public function handle(object $event): void;
}
