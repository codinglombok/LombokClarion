<?php

declare(strict_types=1);

namespace Lombok\TableSheet\Formats;

use Lombok\TableSheet\Core\Workbook;

/** Mirrors src/formats/csv.ts. Uses PHP's native str_getcsv-style parsing
 *  via fgetcsv on an in-memory stream for RFC-4180 correctness (quoted
 *  fields, embedded commas/newlines) rather than hand-rolling a parser
 *  twice — the *behavior* is what's ported, not necessarily every line. */
final class ImportWarning
{
    public function __construct(public string $message)
    {
    }
}

final class ImportResult
{
    /** @param ImportWarning[] $warnings */
    public function __construct(public ?Workbook $workbook, public array $warnings)
    {
    }
}

final class CsvCodec
{
    private const DEFAULT_MAX_INPUT_BYTES = 100 * 1024 * 1024; // 100MB

    private static function coerce(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }
        if ($raw === 'true') {
            return true;
        }
        if ($raw === 'false') {
            return false;
        }
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }
        return $raw;
    }

    public static function decode(string $text, string $delimiter = ',', string $sheetName = 'Sheet1', string $locale = 'en-US', int $maxInputBytes = self::DEFAULT_MAX_INPUT_BYTES): ImportResult
    {
        if (strlen($text) > $maxInputBytes) {
            return new ImportResult(null, [new ImportWarning("Input exceeds the configured size limit of {$maxInputBytes} bytes; refusing to parse (possible resource-exhaustion attempt)")]);
        }
        try {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $text);
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
                if ($row === [null]) {
                    continue; // blank line
                }
                $rows[] = array_map(fn ($v) => self::coerce((string) $v), $row);
            }
            fclose($stream);
            $workbook = Workbook::fromRows($rows, $sheetName, $locale);
            return new ImportResult($workbook, []);
        } catch (\Throwable $e) {
            return new ImportResult(null, [new ImportWarning('CSV parse failed: ' . $e->getMessage())]);
        }
    }

    public static function encode(Workbook $workbook, ?string $sheetName = null, string $delimiter = ','): string
    {
        $sheet = $sheetName !== null ? $workbook->sheet($sheetName) : ($workbook->sheets[0] ?? null);
        if ($sheet === null) {
            return '';
        }
        $stream = fopen('php://memory', 'r+');
        foreach ($sheet->toRows() as $row) {
            $normalized = array_map(fn ($v) => $v === null ? '' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v), $row);
            fputcsv($stream, $normalized, $delimiter, '"', '\\', "\r\n");
        }
        rewind($stream);
        $out = stream_get_contents($stream);
        fclose($stream);
        return rtrim($out, "\r\n");
    }
}
