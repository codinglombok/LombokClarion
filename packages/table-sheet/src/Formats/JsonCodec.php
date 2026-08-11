<?php

declare(strict_types=1);

namespace Lombok\TableSheet\Formats;

use Lombok\TableSheet\Core\Workbook;

/** Mirrors src/formats/json.ts. */
final class JsonCodec
{
    private const DEFAULT_MAX_INPUT_BYTES = 100 * 1024 * 1024; // 100MB

    public static function decode(string $text, string $sheetName = 'Sheet1', string $locale = 'en-US', int $maxInputBytes = self::DEFAULT_MAX_INPUT_BYTES): ImportResult
    {
        if (strlen($text) > $maxInputBytes) {
            return new ImportResult(null, [new ImportWarning("Input exceeds the configured size limit of {$maxInputBytes} bytes; refusing to parse (possible resource-exhaustion attempt)")]);
        }
        try {
            $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data) === false && $data !== []) {
                // allow associative-empty-array edge case; otherwise require a list
            }
            if (!is_array($data) || ($data !== [] && !array_is_list($data))) {
                return new ImportResult(null, [new ImportWarning('JSON root must be an array of records')]);
            }

            $keys = [];
            foreach ($data as $rec) {
                if (is_array($rec)) {
                    foreach (array_keys($rec) as $k) {
                        if (!in_array($k, $keys, true)) {
                            $keys[] = $k;
                        }
                    }
                }
            }

            $rows = [$keys];
            foreach ($data as $rec) {
                $row = [];
                foreach ($keys as $k) {
                    $row[] = (is_array($rec) && array_key_exists($k, $rec)) ? $rec[$k] : null;
                }
                $rows[] = $row;
            }

            $workbook = Workbook::fromRows($rows, $sheetName, $locale);
            return new ImportResult($workbook, []);
        } catch (\Throwable $e) {
            return new ImportResult(null, [new ImportWarning('JSON parse failed: ' . $e->getMessage())]);
        }
    }

    public static function encode(Workbook $workbook, ?string $sheetName = null, bool $pretty = true): string
    {
        $sheet = $sheetName !== null ? $workbook->sheet($sheetName) : ($workbook->sheets[0] ?? null);
        if ($sheet === null) {
            return '[]';
        }
        $rows = $sheet->toRows();
        if (count($rows) === 0) {
            return '[]';
        }
        $header = $rows[0];
        $records = [];
        for ($i = 1; $i < count($rows); $i++) {
            $rec = [];
            foreach ($header as $c => $h) {
                $key = $h ?? "col{$c}";
                $rec[(string) $key] = $rows[$i][$c] ?? null;
            }
            $records[] = $rec;
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0);
        return json_encode($records, $flags);
    }
}

/** Mirrors the write-only Markdown export in src/formats/json.ts. */
final class MarkdownCodec
{
    private static function esc(mixed $v): string
    {
        return str_replace(["\n"], [' '], str_replace('|', '\\|', (string) ($v ?? '')));
    }

    public static function encode(Workbook $workbook, ?string $sheetName = null): string
    {
        $sheet = $sheetName !== null ? $workbook->sheet($sheetName) : ($workbook->sheets[0] ?? null);
        if ($sheet === null) {
            return '';
        }
        $rows = $sheet->toRows();
        if (count($rows) === 0) {
            return '';
        }
        $header = $rows[0];
        $lines = [];
        $lines[] = '| ' . implode(' | ', array_map([self::class, 'esc'], $header)) . ' |';
        $lines[] = '| ' . implode(' | ', array_fill(0, count($header), '---')) . ' |';
        for ($i = 1; $i < count($rows); $i++) {
            $lines[] = '| ' . implode(' | ', array_map([self::class, 'esc'], $rows[$i])) . ' |';
        }
        return implode("\n", $lines);
    }
}
