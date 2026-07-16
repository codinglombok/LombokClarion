<?php

declare(strict_types=1);

namespace LombokClarion\Testing;

use LombokClarion\Container\CompiledContainer;
use RuntimeException;

/**
 * Shipped by default so CI fails on a cold-start regression instead of it
 * being discovered in production (master prompt §5, §9). Runs against the
 * REAL compiled services file the deploy step produces — not a synthetic
 * stand-in — so it actually measures what ships.
 *
 * Note: this measures container construction + resolving the given root
 * services with zero reflection. It does not include PHP process startup,
 * opcache warm-up, or autoloader cost, since those are constant across
 * requests once the process/worker is warm and are outside this
 * framework's control.
 */
final class ColdStartTest extends BenchmarkTestCase
{
    /**
     * @param list<string> $rootServiceIds services to resolve as part of
     *        the measured boot (e.g. the controllers on the hot path)
     */
    public function assertContainerBootUnder(
        string $compiledFilePath,
        float $maxMicroseconds,
        array $rootServiceIds = [],
    ): float {
        $elapsed = $this->measureMicroseconds(function () use ($compiledFilePath, $rootServiceIds) {
            $container = CompiledContainer::fromFile($compiledFilePath);
            foreach ($rootServiceIds as $id) {
                $container->get($id);
            }
        });

        if ($elapsed > $maxMicroseconds) {
            throw new RuntimeException(sprintf(
                'Cold-start budget exceeded: container boot took %.2fµs, budget is %.2fµs.',
                $elapsed,
                $maxMicroseconds
            ));
        }

        return $elapsed;
    }
}
