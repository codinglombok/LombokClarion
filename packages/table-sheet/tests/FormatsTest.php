<?php

declare(strict_types=1);

namespace Lombok\TableSheet\Tests;

use Lombok\TableSheet\Formats\CsvCodec;
use Lombok\TableSheet\Formats\JsonCodec;
use Lombok\TableSheet\Formats\MarkdownCodec;
use Lombok\TableSheet\Core\Workbook;
use PHPUnit\Framework\TestCase;

final class FormatsTest extends TestCase
{
    public function testCsvDecodeBasicGridWithTypeCoercion(): void
    {
        $result = CsvCodec::decode("name,age\nAlice,30\nBob,25\n");
        $this->assertCount(0, $result->warnings);
        $rows = $result->workbook->sheets[0]->toRows();
        $this->assertSame([['name', 'age'], ['Alice', 30], ['Bob', 25]], $rows);
    }

    public function testCsvDecodeQuotedFieldsWithEmbeddedCommasAndQuotes(): void
    {
        $result = CsvCodec::decode("a,b\n\"hello, world\",\"she said \"\"hi\"\"\"\n");
        $rows = $result->workbook->sheets[0]->toRows();
        $this->assertSame('hello, world', $rows[1][0]);
        $this->assertSame('she said "hi"', $rows[1][1]);
    }

    public function testCsvRoundTrip(): void
    {
        $original = "name,age\nAlice,30\nBob,25";
        $decoded = CsvCodec::decode($original);
        $out = CsvCodec::encode($decoded->workbook);
        $reparsed = CsvCodec::decode($out);
        $this->assertSame($decoded->workbook->sheets[0]->toRows(), $reparsed->workbook->sheets[0]->toRows());
    }

    public function testJsonRoundTripViaArrayOfRecords(): void
    {
        $input = json_encode([['name' => 'Alice', 'age' => 30], ['name' => 'Bob', 'age' => 25]]);
        $result = JsonCodec::decode($input);
        $out = json_decode(JsonCodec::encode($result->workbook), true);
        $this->assertSame([['name' => 'Alice', 'age' => 30], ['name' => 'Bob', 'age' => 25]], $out);
    }

    public function testJsonDecodeNonArrayRootProducesWarning(): void
    {
        $result = JsonCodec::decode('{"not":"an array"}');
        $this->assertNull($result->workbook);
        $this->assertNotCount(0, $result->warnings);
    }

    public function testMarkdownExportProducesGfmTable(): void
    {
        $wb = Workbook::fromRows([['a', 'b'], [1, 2]]);
        $md = MarkdownCodec::encode($wb);
        $this->assertStringContainsString('| a | b |', $md);
        $this->assertStringContainsString('| --- | --- |', $md);
        $this->assertStringContainsString('| 1 | 2 |', $md);
    }
}
