<?php

declare(strict_types=1);

namespace Lombok\TableSheet\Core;

/**
 * Formula engine — mirrors src/core/formula.ts. Deliberately no `eval()`
 * (PHP's `eval` is the exact same code-injection risk as JS's) — same
 * tokenizer -> Pratt parser -> AST -> evaluator shape as the TS core.
 */
final class FormulaError
{
    public function __construct(public string $code)
    {
    }

    public function __toString(): string
    {
        return $this->code;
    }
}

interface CellResolver
{
    public function resolve(string $ref): mixed;
}

final class Token
{
    public function __construct(public string $type, public string $value)
    {
    }
}

final class FormulaLexer
{
    /** @return Token[] */
    public static function tokenize(string $input): array
    {
        $src = ltrim(trim($input), '=');
        $tokens = [];
        $i = 0;
        $len = strlen($src);

        while ($i < $len) {
            $c = $src[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '(') {
                $tokens[] = new Token('LPAREN', $c);
                $i++;
                continue;
            }
            if ($c === ')') {
                $tokens[] = new Token('RPAREN', $c);
                $i++;
                continue;
            }
            if ($c === ',') {
                $tokens[] = new Token('COMMA', $c);
                $i++;
                continue;
            }
            if ($c === '"') {
                $j = $i + 1;
                $str = '';
                while ($j < $len && $src[$j] !== '"') {
                    $str .= $src[$j];
                    $j++;
                }
                $tokens[] = new Token('STRING', $str);
                $i = $j + 1;
                continue;
            }
            if (ctype_digit($c)) {
                $j = $i;
                $num = '';
                while ($j < $len && (ctype_digit($src[$j]) || $src[$j] === '.')) {
                    $num .= $src[$j];
                    $j++;
                }
                $tokens[] = new Token('NUMBER', $num);
                $i = $j;
                continue;
            }
            if (ctype_alpha($c)) {
                $j = $i;
                $ident = '';
                while ($j < $len && ctype_alnum($src[$j])) {
                    $ident .= $src[$j];
                    $j++;
                }
                if (preg_match('/^[A-Z]+[0-9]+$/', $ident)) {
                    if ($j < $len && $src[$j] === ':') {
                        $k = $j + 1;
                        $ident2 = '';
                        while ($k < $len && ctype_alnum($src[$k])) {
                            $ident2 .= $src[$k];
                            $k++;
                        }
                        if (preg_match('/^[A-Z]+[0-9]+$/', $ident2)) {
                            $tokens[] = new Token('RANGE', "{$ident}:{$ident2}");
                            $i = $k;
                            continue;
                        }
                    }
                    $tokens[] = new Token('REF', $ident);
                    $i = $j;
                    continue;
                }
                $tokens[] = new Token('IDENT', strtoupper($ident));
                $i = $j;
                continue;
            }
            if (str_contains('+-*/^', $c)) {
                $tokens[] = new Token('OP', $c);
                $i++;
                continue;
            }
            if ($c === '=' || $c === '<' || $c === '>') {
                $op = $c;
                $i++;
                if (($i < $len && $src[$i] === '=' && $c !== '=') || ($c === '<' && $i < $len && $src[$i] === '>')) {
                    $op .= $src[$i];
                    $i++;
                }
                $tokens[] = new Token('OP', $op);
                continue;
            }
            throw new \RuntimeException("Unexpected character in formula: '{$c}'");
        }
        $tokens[] = new Token('EOF', '');
        return $tokens;
    }
}

abstract class Node
{
}

final class NumNode extends Node
{
    public function __construct(public float|int $value)
    {
    }
}
final class StrNode extends Node
{
    public function __construct(public string $value)
    {
    }
}
final class RefNode extends Node
{
    public function __construct(public string $value)
    {
    }
}
final class RangeNode extends Node
{
    public function __construct(public string $from, public string $to)
    {
    }
}
final class CallNode extends Node
{
    /** @param Node[] $args */
    public function __construct(public string $name, public array $args)
    {
    }
}
final class BinOpNode extends Node
{
    public function __construct(public string $op, public Node $left, public Node $right)
    {
    }
}
final class UnaryNode extends Node
{
    public function __construct(public string $op, public Node $arg)
    {
    }
}

final class FormulaParser
{
    private const PRECEDENCE = [
        '=' => 1, '<>' => 1, '<' => 1, '<=' => 1, '>' => 1, '>=' => 1,
        '+' => 2, '-' => 2,
        '*' => 3, '/' => 3,
        '^' => 4,
    ];
    private const MAX_DEPTH = 200;

    /** @var Token[] */
    private array $tokens;
    private int $pos = 0;
    private int $depth = 0;

    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos] ?? new Token('EOF', '');
    }

    private function next(): Token
    {
        $t = $this->peek();
        $this->pos++;
        return $t;
    }

    public function parse(): Node
    {
        $node = $this->parseExpr(0);
        if ($this->peek()->type !== 'EOF') {
            throw new \RuntimeException("Unexpected token: {$this->peek()->value}");
        }
        return $node;
    }

    private function parseExpr(int $minPrec): Node
    {
        $this->depth++;
        if ($this->depth > self::MAX_DEPTH) {
            throw new \RuntimeException('Formula nesting exceeds the maximum supported depth (' . self::MAX_DEPTH . ') — refusing to parse further to avoid a stack overflow');
        }
        try {
            $left = $this->parseUnary();
            while (true) {
                $tok = $this->peek();
                if ($tok->type !== 'OP') {
                    break;
                }
                $prec = self::PRECEDENCE[$tok->value] ?? null;
                if ($prec === null || $prec < $minPrec) {
                    break;
                }
                $this->next();
                $right = $this->parseExpr($prec + 1);
                $left = new BinOpNode($tok->value, $left, $right);
            }
            return $left;
        } finally {
            $this->depth--;
        }
    }

    private function parseUnary(): Node
    {
        $tok = $this->peek();
        if ($tok->type === 'OP' && ($tok->value === '-' || $tok->value === '+')) {
            $this->depth++;
            if ($this->depth > self::MAX_DEPTH) {
                throw new \RuntimeException('Formula nesting exceeds the maximum supported depth (' . self::MAX_DEPTH . ') — refusing to parse further to avoid a stack overflow');
            }
            try {
                $this->next();
                return new UnaryNode($tok->value, $this->parseUnary());
            } finally {
                $this->depth--;
            }
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): Node
    {
        $tok = $this->next();
        return match ($tok->type) {
            'NUMBER' => new NumNode(str_contains($tok->value, '.') ? (float) $tok->value : (int) $tok->value),
            'STRING' => new StrNode($tok->value),
            'REF' => new RefNode($tok->value),
            'RANGE' => (function () use ($tok) {
                [$from, $to] = explode(':', $tok->value);
                return new RangeNode($from, $to);
            })(),
            'IDENT' => $this->parseCall($tok->value),
            'LPAREN' => (function () {
                $inner = $this->parseExpr(0);
                if ($this->peek()->type !== 'RPAREN') {
                    throw new \RuntimeException('Expected )');
                }
                $this->next();
                return $inner;
            })(),
            default => throw new \RuntimeException("Unexpected token: {$tok->value}"),
        };
    }

    private function parseCall(string $name): Node
    {
        if ($this->peek()->type !== 'LPAREN') {
            throw new \RuntimeException("Unknown identifier: {$name}");
        }
        $this->next();
        $args = [];
        if ($this->peek()->type !== 'RPAREN') {
            $args[] = $this->parseExpr(0);
            while ($this->peek()->type === 'COMMA') {
                $this->next();
                $args[] = $this->parseExpr(0);
            }
        }
        if ($this->peek()->type !== 'RPAREN') {
            throw new \RuntimeException('Expected )');
        }
        $this->next();
        return new CallNode($name, $args);
    }
}

final class CellRefUtil
{
    public static function parse(string $ref): array
    {
        if (!preg_match('/^([A-Z]+)([0-9]+)$/', $ref, $m)) {
            throw new \InvalidArgumentException("Invalid cell reference: {$ref}");
        }
        $col = 0;
        foreach (str_split($m[1]) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
            if (is_infinite($col) || $col > 16384) {
                throw new \InvalidArgumentException("Invalid cell reference: {$ref} (column overflow)");
            }
        }
        return ['row' => ((int) $m[2]) - 1, 'col' => $col - 1];
    }

    public static function name(int $row, int $col): string
    {
        $c = $col + 1;
        $letters = '';
        while ($c > 0) {
            $rem = ($c - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $c = intdiv($c - 1, 26);
        }
        return "{$letters}" . ($row + 1);
    }
}

final class FormulaEngine
{
    private const MAX_RANGE_CELLS = 1_000_000;

    public static function parse(string $formula): Node
    {
        return (new FormulaParser(FormulaLexer::tokenize($formula)))->parse();
    }

    /** @return string[] */
    public static function extractDependencies(Node $node): array
    {
        $refs = [];
        $walk = function (Node $n) use (&$walk, &$refs) {
            if ($n instanceof RefNode) {
                $refs[$n->value] = true;
            } elseif ($n instanceof RangeNode) {
                $from = CellRefUtil::parse($n->from);
                $to = CellRefUtil::parse($n->to);
                for ($r = min($from['row'], $to['row']); $r <= max($from['row'], $to['row']); $r++) {
                    for ($c = min($from['col'], $to['col']); $c <= max($from['col'], $to['col']); $c++) {
                        $refs[CellRefUtil::name($r, $c)] = true;
                    }
                }
            } elseif ($n instanceof CallNode) {
                foreach ($n->args as $a) {
                    $walk($a);
                }
            } elseif ($n instanceof BinOpNode) {
                $walk($n->left);
                $walk($n->right);
            } elseif ($n instanceof UnaryNode) {
                $walk($n->arg);
            }
        };
        $walk($node);
        return array_keys($refs);
    }

    private static function flattenNums(array $args): array
    {
        $out = [];
        foreach ($args as $group) {
            foreach ($group as $v) {
                if (is_int($v) || is_float($v)) {
                    $out[] = $v;
                }
            }
        }
        return $out;
    }

    /** @return array<string, callable(array): mixed> */
    private static function functions(): array
    {
        return [
            'SUM' => fn (array $args) => array_sum(self::flattenNums($args)),
            'AVG' => function (array $args) {
                $nums = self::flattenNums($args);
                return count($nums) ? array_sum($nums) / count($nums) : 0;
            },
            'MIN' => fn (array $args) => min(self::flattenNums($args) ?: [0]),
            'MAX' => fn (array $args) => max(self::flattenNums($args) ?: [0]),
            'COUNT' => fn (array $args) => count(self::flattenNums($args)),
            'ROUND' => function (array $args) {
                $nums = self::flattenNums($args);
                $val = $nums[0] ?? 0;
                $digits = (int) ($nums[1] ?? 0);
                return round((float) $val, $digits);
            },
            'IF' => fn (array $args) => ($args[0][0] ?? false) ? ($args[1][0] ?? '') : ($args[2][0] ?? ''),
            'CONCAT' => function (array $args) {
                $flat = array_merge(...array_values($args));
                return implode('', array_map(fn ($v) => (string) $v, $flat));
            },
        ];
    }

    public static function evaluate(Node $node, CellResolver $resolver): mixed
    {
        try {
            return self::evalNode($node, $resolver);
        } catch (FormulaException $e) {
            return $e->error;
        } catch (\Throwable) {
            return new FormulaError('#ERROR!');
        }
    }

    private static function evalNode(Node $node, CellResolver $resolver): mixed
    {
        if ($node instanceof NumNode) {
            return $node->value;
        }
        if ($node instanceof StrNode) {
            return $node->value;
        }
        if ($node instanceof RefNode) {
            return $resolver->resolve($node->value);
        }
        if ($node instanceof RangeNode) {
            throw new FormulaException(new FormulaError('#VALUE!'));
        }
        if ($node instanceof UnaryNode) {
            $v = self::evalNode($node->arg, $resolver);
            if (!is_int($v) && !is_float($v)) {
                return new FormulaError('#VALUE!');
            }
            return $node->op === '-' ? -$v : $v;
        }
        if ($node instanceof BinOpNode) {
            if (in_array($node->op, ['=', '<>', '<', '<=', '>', '>='], true)) {
                $l = self::evalNode($node->left, $resolver);
                $r = self::evalNode($node->right, $resolver);
                return match ($node->op) {
                    '=' => $l === $r,
                    '<>' => $l !== $r,
                    '<' => $l < $r,
                    '<=' => $l <= $r,
                    '>' => $l > $r,
                    '>=' => $l >= $r,
                };
            }
            $l = self::evalNode($node->left, $resolver);
            $r = self::evalNode($node->right, $resolver);
            if ((!is_int($l) && !is_float($l)) || (!is_int($r) && !is_float($r))) {
                return new FormulaError('#VALUE!');
            }
            return match ($node->op) {
                '+' => $l + $r,
                '-' => $l - $r,
                '*' => $l * $r,
                '/' => $r === 0 || $r === 0.0 ? new FormulaError('#DIV/0!') : $l / $r,
                '^' => $l ** $r,
                default => new FormulaError('#ERROR!'),
            };
        }
        if ($node instanceof CallNode) {
            $fns = self::functions();
            $fn = $fns[$node->name] ?? null;
            if ($fn === null) {
                return new FormulaError('#NAME?');
            }
            $args = [];
            foreach ($node->args as $arg) {
                if ($arg instanceof RangeNode) {
                    $from = CellRefUtil::parse($arg->from);
                    $to = CellRefUtil::parse($arg->to);
                    $rangeRows = abs($to['row'] - $from['row']) + 1;
                    $rangeCols = abs($to['col'] - $from['col']) + 1;
                    if ($rangeRows * $rangeCols > self::MAX_RANGE_CELLS) {
                        $args[] = [new FormulaError('#VALUE!')];
                        continue;
                    }
                    $values = [];
                    for ($r = min($from['row'], $to['row']); $r <= max($from['row'], $to['row']); $r++) {
                        for ($c = min($from['col'], $to['col']); $c <= max($from['col'], $to['col']); $c++) {
                            $values[] = $resolver->resolve(CellRefUtil::name($r, $c));
                        }
                    }
                    $args[] = $values;
                } else {
                    $args[] = [self::evalNode($arg, $resolver)];
                }
            }
            return $fn($args);
        }
        return new FormulaError('#ERROR!');
    }

    public static function sheetResolver(Sheet $sheet, array $visiting = []): CellResolver
    {
        return new class ($sheet, $visiting) implements CellResolver {
            public function __construct(private Sheet $sheet, private array $visiting)
            {
            }

            public function resolve(string $ref): mixed
            {
                if (isset($this->visiting[$ref])) {
                    return new FormulaError('#CIRC!');
                }
                $pos = CellRefUtil::parse($ref);
                $cell = $this->sheet->getCell($pos['row'], $pos['col']);
                if ($cell->type === 'formula' && $cell->formula !== null) {
                    $nextVisiting = $this->visiting;
                    $nextVisiting[$ref] = true;
                    $ast = FormulaEngine::parse($cell->formula);
                    return FormulaEngine::evaluate($ast, FormulaEngine::sheetResolver($this->sheet, $nextVisiting));
                }
                return $cell->value ?? 0;
            }
        };
    }
}

/** Internal control-flow exception used to short-circuit evaluation on a FormulaError. */
final class FormulaException extends \RuntimeException
{
    public function __construct(public FormulaError $error)
    {
        parent::__construct($error->code);
    }
}
