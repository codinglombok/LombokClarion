<?php

declare(strict_types=1);

namespace Lombok\TableSheet\Tests;

use Lombok\TableSheet\Core\SplitMerge;
use Lombok\TableSheet\Core\Workbook;
use PHPUnit\Framework\TestCase;

final class SplitMergeTest extends TestCase
{
    private function wb(array $rows, string $name = 'Sheet1'): Workbook
    {
        return Workbook::fromRows($rows, $name);
    }

    public function testSplitByRows(): void
    {
        $source = $this->wb([['h1', 'h2'], [1, 2], [3, 4], [5, 6]]);
        [$top, $bottom] = SplitMerge::splitByRows($source, 'Sheet1', 2);
        $this->assertSame([['h1', 'h2'], [1, 2]], $top->sheets[0]->toRows());
        $this->assertSame([[3, 4], [5, 6]], $bottom->sheets[0]->toRows());
    }

    public function testSplitByColumns(): void
    {
        $source = $this->wb([['a', 'b', 'c'], [1, 2, 3]]);
        [$left, $right] = SplitMerge::splitByColumns($source, 'Sheet1', 1);
        $this->assertSame([['a'], [1]], $left->sheets[0]->toRows());
        $this->assertSame([['b', 'c'], [2, 3]], $right->sheets[0]->toRows());
    }

    public function testSplitBySheet(): void
    {
        $source = new Workbook();
        $source->addSheet($this->wb([['a']], 'One')->sheets[0]);
        $source->addSheet($this->wb([['b']], 'Two')->sheets[0]);
        $parts = SplitMerge::splitBySheet($source);
        $this->assertCount(2, $parts);
        $this->assertSame('One', $parts[0]->sheets[0]->name);
        $this->assertSame('Two', $parts[1]->sheets[0]->name);
    }

    public function testMergeCombinesSameNamedSheetsRowWise(): void
    {
        $a = $this->wb([['h1', 'h2'], [1, 2]]);
        $b = $this->wb([['h1', 'h2'], [3, 4]]);
        $merged = SplitMerge::merge([$a, $b]);
        $this->assertSame([['h1', 'h2'], [1, 2], ['h1', 'h2'], [3, 4]], $merged->sheets[0]->toRows());
    }

    public function testMergeWithErrorStrategyThrowsOnDuplicateNames(): void
    {
        $a = $this->wb([['x']], 'Sheet1');
        $b = $this->wb([['y']], 'Sheet1');
        $this->expectException(\RuntimeException::class);
        SplitMerge::merge([$a, $b], 'error');
    }

    public function testMergeWithEmptyArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SplitMerge::merge([]);
    }
}
