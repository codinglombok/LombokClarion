<?php

declare(strict_types=1);

namespace Lombok\TableSheet\Core;

/** Mirrors src/core/splitMerge.ts. */
final class SplitMerge
{
    /** @return array{0: Workbook, 1: Workbook} */
    public static function splitByRows(Workbook $workbook, string $sheetName, int $at): array
    {
        $sheet = $workbook->sheet($sheetName);
        if ($sheet === null) {
            throw new \InvalidArgumentException("Sheet not found: {$sheetName}");
        }
        $rows = $sheet->toRows();
        $top = array_slice($rows, 0, $at);
        $bottom = array_slice($rows, $at);
        return [
            Workbook::fromRows($top, "{$sheet->name}_part1", $workbook->locale),
            Workbook::fromRows($bottom, "{$sheet->name}_part2", $workbook->locale),
        ];
    }

    /** @return array{0: Workbook, 1: Workbook} */
    public static function splitByColumns(Workbook $workbook, string $sheetName, int $at): array
    {
        $sheet = $workbook->sheet($sheetName);
        if ($sheet === null) {
            throw new \InvalidArgumentException("Sheet not found: {$sheetName}");
        }
        $rows = $sheet->toRows();
        $left = array_map(fn ($r) => array_slice($r, 0, $at), $rows);
        $right = array_map(fn ($r) => array_slice($r, $at), $rows);
        return [
            Workbook::fromRows($left, "{$sheet->name}_left", $workbook->locale),
            Workbook::fromRows($right, "{$sheet->name}_right", $workbook->locale),
        ];
    }

    /** @return Workbook[] */
    public static function splitBySheet(Workbook $workbook): array
    {
        return array_map(function (Sheet $s) use ($workbook) {
            $wb = new Workbook($workbook->locale);
            $wb->addSheet($s->clone());
            return $wb;
        }, $workbook->sheets);
    }

    /** @param Workbook[] $workbooks */
    public static function merge(array $workbooks, string $onConflict = 'left-wins'): Workbook
    {
        if (count($workbooks) === 0) {
            throw new \InvalidArgumentException('merge() requires at least one workbook');
        }
        $result = new Workbook($workbooks[0]->locale);
        /** @var array<string, array<int, array<int, mixed>>> */
        $bySheetName = [];
        /** @var array<string, int> */
        $seenCount = [];

        foreach ($workbooks as $wb) {
            foreach ($wb->sheets as $sheet) {
                $rows = $sheet->toRows();
                $seenCount[$sheet->name] = ($seenCount[$sheet->name] ?? 0) + 1;
                if (!isset($bySheetName[$sheet->name])) {
                    $bySheetName[$sheet->name] = $rows;
                } else {
                    $existing = &$bySheetName[$sheet->name];
                    $width = max(count($existing[0] ?? []), count($rows[0] ?? []));
                    foreach ($rows as $row) {
                        $padded = $row;
                        while (count($padded) < $width) {
                            $padded[] = null;
                        }
                        $existing[] = $padded;
                    }
                    unset($existing);
                }
            }
        }

        if ($onConflict === 'error') {
            $dupes = array_keys(array_filter($seenCount, fn ($n) => $n > 1));
            if (count($dupes) > 0) {
                throw new \RuntimeException('Sheet name conflicts during merge (strategy=error): ' . implode(', ', $dupes));
            }
        }

        foreach ($bySheetName as $name => $rows) {
            $result->addSheet(Workbook::fromRows($rows, $name, $result->locale)->sheets[0]);
        }
        return $result;
    }
}
