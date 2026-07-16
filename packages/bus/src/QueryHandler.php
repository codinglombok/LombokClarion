<?php

declare(strict_types=1);

namespace LombokClarion\Bus;

/**
 * @template TQuery of object
 */
interface QueryHandler
{
    /**
     * @param TQuery $query
     */
    public function handle(object $query): mixed;
}
