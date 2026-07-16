<?php

declare(strict_types=1);

namespace LombokClarion\Testing;

class BenchmarkTestCase
{
    protected function measureMicroseconds(callable $fn): float
    {
        $start = hrtime(true);
        $fn();
        return (hrtime(true) - $start) / 1000;
    }
}
