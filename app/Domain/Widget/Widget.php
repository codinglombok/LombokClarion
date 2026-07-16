<?php

declare(strict_types=1);

namespace App\Domain\Widget;

/**
 * Plain PHP. No LombokClarion\* imports anywhere in app/Domain/** — this
 * is enforced in CI (see bin/check-domain-boundary.php, standing in for
 * Deptrac per master prompt §3's hard rule).
 */
final class Widget
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $priceCents,
    ) {
    }
}
