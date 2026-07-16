<?php

declare(strict_types=1);

namespace App\Domain\Widget;

final class ListWidgetsHandler
{
    public function __construct(private readonly WidgetRepositoryInterface $repository)
    {
    }

    /** @return list<Widget> */
    public function handle(object $query): array
    {
        return $this->repository->all();
    }
}
