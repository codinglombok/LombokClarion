<?php

declare(strict_types=1);

namespace Lombok\TableSheet\Tests;

use Lombok\TableSheet\Core\Sheet;
use Lombok\TableSheet\Core\Workbook;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    public function testSetCellGetCellRoundTrip(): void
    {
        $sheet = new Sheet('S1');
        $sheet->setValue(0, 0, 'Name');
        $sheet->setValue(0, 1, 'Age');
        $sheet->setValue(1, 0, 'Alice');
        $sheet->setValue(1, 1, 30);

        $this->assertSame('Name', $sheet->getCell(0, 0)->value);
        $this->assertSame(30, $sheet->getCell(1, 1)->value);
        $this->assertSame('number', $sheet->getCell(1, 1)->type);
        $this->assertSame(2, $sheet->rowCount);
        $this->assertSame(2, $sheet->colCount);
    }

    public function testGetCellOnUnsetCellReturnsEmpty(): void
    {
        $sheet = new Sheet('S1');
        $cell = $sheet->getCell(5, 5);
        $this->assertNull($cell->value);
        $this->assertSame('empty', $cell->type);
    }

    public function testSetCellRejectsNegativeCoordinates(): void
    {
        $this->expectException(\RangeException::class);
        (new Sheet('S1'))->setCell(-1, 0, new \Lombok\TableSheet\Core\Cell(1, 'number'));
    }

    public function testWorkbookFromRowsBuildsCorrectDimensions(): void
    {
        $wb = Workbook::fromRows([
            ['a', 'b', 'c'],
            [1, 2, 3],
        ]);
        $sheet = $wb->sheets[0];
        $this->assertSame(2, $sheet->rowCount);
        $this->assertSame(3, $sheet->colCount);
        $this->assertSame([['a', 'b', 'c'], [1, 2, 3]], $sheet->toRows());
    }

    public function testSheetCloneIsDeepAndIndependent(): void
    {
        $sheet = new Sheet('S1');
        $sheet->setValue(0, 0, 'x');
        $clone = $sheet->clone();
        $clone->setValue(0, 0, 'y');
        $this->assertSame('x', $sheet->getCell(0, 0)->value);
        $this->assertSame('y', $clone->getCell(0, 0)->value);
    }
}
