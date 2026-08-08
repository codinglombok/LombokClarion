<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Seeders;

use LombokClarion\Persistence\SeedContext;
use LombokClarion\Persistence\Seeder;

/**
 * Demo rows for the widgets table.
 *
 * Every value comes from the run's Factory rather than from a global random
 * source, so `seed --seed=N` twice produces byte-identical rows and a fixture
 * that broke something can be put back.
 */
final class DemoWidgetsSeeder implements Seeder
{
    private const COUNT = 5;

    public function seed(SeedContext $context): void
    {
        $factory = $context->factory();

        for ($i = 0; $i < self::COUNT; $i++) {
            $context->table('widgets')->insert([
                'id' => $factory->id(),
                'name' => ucfirst($factory->words(2)),
                'price_cents' => $factory->int(100, 500_00),
            ]);
        }
    }
}
