<?php

declare(strict_types=1);

namespace Lombok\TableSheet\Tests;

use Lombok\TableSheet\Core\Cell;
use Lombok\TableSheet\Core\CellRefUtil;
use Lombok\TableSheet\Core\FormulaEngine;
use Lombok\TableSheet\Core\FormulaError;
use Lombok\TableSheet\Core\Sheet;
use PHPUnit\Framework\TestCase;

final class FormulaTest extends TestCase
{
    private function evalFormula(Sheet $sheet, string $formula): mixed
    {
        $ast = FormulaEngine::parse($formula);
        return FormulaEngine::evaluate($ast, FormulaEngine::sheetResolver($sheet));
    }

    public function testArithmeticOperatorPrecedence(): void
    {
        $sheet = new Sheet('S1');
        $this->assertSame(14, $this->evalFormula($sheet, '=2+3*4'));
        $this->assertSame(20, $this->evalFormula($sheet, '=(2+3)*4'));
        $this->assertSame(9, $this->evalFormula($sheet, '=2^3+1'));
        $this->assertSame(-3, $this->evalFormula($sheet, '=-5+2'));
    }

    public function testCellReferencesResolveToSheetValues(): void
    {
        $sheet = new Sheet('S1');
        $sheet->setValue(0, 0, 10);
        $sheet->setValue(1, 0, 20);
        $this->assertSame(30, $this->evalFormula($sheet, '=A1+A2'));
    }

    public function testSumAvgMinMaxOverRange(): void
    {
        $sheet = new Sheet('S1');
        foreach ([1, 2, 3, 4, 5] as $i => $v) {
            $sheet->setValue($i, 0, $v);
        }
        $this->assertSame(15, $this->evalFormula($sheet, '=SUM(A1:A5)'));
        $this->assertEqualsWithDelta(3, $this->evalFormula($sheet, '=AVG(A1:A5)'), 0.0001);
        $this->assertSame(1, $this->evalFormula($sheet, '=MIN(A1:A5)'));
        $this->assertSame(5, $this->evalFormula($sheet, '=MAX(A1:A5)'));
        $this->assertSame(5, $this->evalFormula($sheet, '=COUNT(A1:A5)'));
    }

    public function testIfAndComparisons(): void
    {
        $sheet = new Sheet('S1');
        $sheet->setValue(0, 0, 10);
        $this->assertSame('big', $this->evalFormula($sheet, '=IF(A1>5,"big","small")'));
        $this->assertSame('small', $this->evalFormula($sheet, '=IF(A1<5,"big","small")'));
    }

    public function testDivisionByZeroYieldsFormulaError(): void
    {
        $sheet = new Sheet('S1');
        $result = $this->evalFormula($sheet, '=10/0');
        $this->assertInstanceOf(FormulaError::class, $result);
        $this->assertSame('#DIV/0!', $result->code);
    }

    public function testUnknownFunctionYieldsNameError(): void
    {
        $sheet = new Sheet('S1');
        $result = $this->evalFormula($sheet, '=NOTAFUNC(1,2)');
        $this->assertInstanceOf(FormulaError::class, $result);
        $this->assertSame('#NAME?', $result->code);
    }

    public function testCircularReferenceIsDetected(): void
    {
        $sheet = new Sheet('S1');
        $sheet->setCell(0, 0, new Cell(null, 'formula', '=B1'));
        $sheet->setCell(0, 1, new Cell(null, 'formula', '=A1'));
        $result = $this->evalFormula($sheet, '=A1');
        $this->assertInstanceOf(FormulaError::class, $result);
        $this->assertSame('#CIRC!', $result->code);
    }

    public function testCellRefNameParseCellRefRoundTrip(): void
    {
        $this->assertSame('A1', CellRefUtil::name(0, 0));
        $this->assertSame('AA1', CellRefUtil::name(0, 26));
        $this->assertSame(['row' => 2, 'col' => 1], CellRefUtil::parse('B3'));
        $this->assertSame(['row' => 9, 'col' => 27], CellRefUtil::parse(CellRefUtil::name(9, 27)));
    }

    public function testExtractDependenciesFindsRefsAndExpandsRanges(): void
    {
        $ast = FormulaEngine::parse('=SUM(A1:A3)+B1');
        $deps = FormulaEngine::extractDependencies($ast);
        sort($deps);
        $this->assertSame(['A1', 'A2', 'A3', 'B1'], $deps);
    }

    public function testConcatAndRound(): void
    {
        $sheet = new Sheet('S1');
        $this->assertSame('abc', $this->evalFormula($sheet, '=CONCAT("a","b","c")'));
        $this->assertEqualsWithDelta(3.14, $this->evalFormula($sheet, '=ROUND(3.14159,2)'), 0.0001);
    }
}
