<?php

declare(strict_types=1);

namespace App\Domain\Widget;

final class CreateWidget
{
    public function __construct(
        public readonly string $name,
        public readonly int $priceCents,
    ) {
    }
}
