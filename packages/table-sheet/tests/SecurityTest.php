<?php

declare(strict_types=1);

namespace Lombok\TableSheet\Tests;

use Lombok\TableSheet\Core\FormulaEngine;
use Lombok\TableSheet\Formats\CsvCodec;
use Lombok\TableSheet\Formats\JsonCodec;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    public function testCsvDecodeRefusesOversizedInput(): void
    {
        $result = CsvCodec::decode("a,b\n1,2\n", ',', 'Sheet1', 'en-US', maxInputBytes: 4);
        $this->assertNull($result->workbook);
        $this->assertStringContainsString('exceeds the configured size limit', $result->warnings[0]->message);
    }

    public function testJsonDecodeRefusesOversizedInput(): void
    {
        $result = JsonCodec::decode('[{"a":1}]', 'Sheet1', 'en-US', maxInputBytes: 2);
        $this->assertNull($result->workbook);
        $this->assertStringContainsString('exceeds the configured size limit', $result->warnings[0]->message);
    }

    public function testCsvDecodeStillWorksNormallyWithDefaultLimits(): void
    {
        $result = CsvCodec::decode("a,b\n1,2\n");
        $this->assertNotNull($result->workbook);
    }

    public function testFormulaParserRefusesDeepParenNesting(): void
    {
        $depth = 5000;
        $pathological = '=' . str_repeat('(', $depth) . '1' . str_repeat(')', $depth);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/maximum supported depth/');
        FormulaEngine::parse($pathological);
    }

    public function testFormulaParserRefusesLongUnaryChains(): void
    {
        $pathological = '=' . str_repeat('-', 5000) . '1';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/maximum supported depth/');
        FormulaEngine::parse($pathological);
    }

    public function testFormulaParserStillHandlesReasonableNesting(): void
    {
        $node = FormulaEngine::parse('=((((1+2))))*3');
        $this->assertNotNull($node);
    }
}
