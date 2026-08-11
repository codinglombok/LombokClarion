<?php

declare(strict_types=1);

namespace Lombok\TableSheet\Core;

/**
 * Core data model — a deliberate structural port of src/core/model.ts.
 * See ARCHITECTURE.md §2.2 for why the TS core was written to make this
 * translation mechanical rather than a redesign.
 */

final class Cell
{
    public function __construct(
        public mixed $value,
        public string $type = 'empty',
        public ?string $formula = null,
        public ?string $styleRef = null,
    ) {
    }

    public function withValue(mixed $value, string $type): self
    {
        return new self($value, $type, $this->formula, $this->styleRef);
    }
}

final class CellRef
{
    public function __construct(public int $row, public int $col)
    {
    }
}

final class Sheet
{
    /** @var array<string, Cell> */
    private array $cells = [];

    public array $merges = [];
    /** @var array<int, string> */
    public array $colTypes = [];

    public function __construct(
        public string $name,
        public int $rowCount = 0,
        public int $colCount = 0,
    ) {
    }

    private function key(int $row, int $col): string
    {
        return "{$row}:{$col}";
    }

    public function getCell(int $row, int $col): Cell
    {
        return $this->cells[$this->key($row, $col)] ?? new Cell(null, 'empty');
    }

    public function setCell(int $row, int $col, Cell $cell): void
    {
        if ($row < 0 || $col < 0) {
            throw new \RangeException("Cell position out of bounds: ($row, $col)");
        }
        $this->cells[$this->key($row, $col)] = $cell;
        if ($row + 1 > $this->rowCount) {
            $this->rowCount = $row + 1;
        }
        if ($col + 1 > $this->colCount) {
            $this->colCount = $col + 1;
        }
    }

    public function setValue(int $row, int $col, mixed $value): void
    {
        $type = match (true) {
            $value === null => 'empty',
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            default => 'string',
        };
        $this->setCell($row, $col, new Cell($value, $type));
    }

    /** @return iterable<array{0: CellRef, 1: Cell}> */
    public function iterCells(): iterable
    {
        foreach ($this->cells as $key => $cell) {
            [$row, $col] = array_map('intval', explode(':', $key));
            yield [new CellRef($row, $col), $cell];
        }
    }

    /** @return array<int, array<int, mixed>> */
    public function toRows(): array
    {
        $rows = [];
        for ($r = 0; $r < $this->rowCount; $r++) {
            $rows[$r] = array_fill(0, $this->colCount, null);
        }
        foreach ($this->iterCells() as [$ref, $cell]) {
            if ($ref->row < 0 || $ref->row >= $this->rowCount || $ref->col < 0 || $ref->col >= $this->colCount) {
                continue;
            }
            $rows[$ref->row][$ref->col] = $cell->value;
        }
        return $rows;
    }

    public function clone(): self
    {
        $copy = new self($this->name, $this->rowCount, $this->colCount);
        foreach ($this->iterCells() as [$ref, $cell]) {
            $copy->setCell($ref->row, $ref->col, clone $cell);
        }
        $copy->merges = $this->merges;
        $copy->colTypes = $this->colTypes;
        return $copy;
    }
}

final class Workbook
{
    /** @var Sheet[] */
    public array $sheets = [];
    public array $styles = [];
    public string $locale;
    public array $meta = ['createdWith' => 'LombokTableSheet-PHP', 'version' => '0.1.0'];

    public function __construct(string $locale = 'en-US')
    {
        $this->locale = $locale;
    }

    public function addSheet(Sheet $sheet): void
    {
        $this->sheets[] = $sheet;
    }

    public function sheet(string $name): ?Sheet
    {
        foreach ($this->sheets as $s) {
            if ($s->name === $name) {
                return $s;
            }
        }
        return null;
    }

    /** @param array<int, array<int, mixed>> $rows */
    public static function fromRows(array $rows, string $sheetName = 'Sheet1', string $locale = 'en-US'): self
    {
        $wb = new self($locale);
        $cols = 0;
        foreach ($rows as $row) {
            $cols = max($cols, count($row));
        }
        $sheet = new Sheet($sheetName, count($rows), $cols);
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $val) {
                $sheet->setValue($r, $c, $val);
            }
        }
        $wb->addSheet($sheet);
        return $wb;
    }
}
