<?php

declare(strict_types=1);

namespace App\Domain\Widget;

interface WidgetRepositoryInterface
{
    public function save(Widget $widget): void;

    public function find(string $id): ?Widget;

    /** @return list<Widget> */
    public function all(): array;
}
