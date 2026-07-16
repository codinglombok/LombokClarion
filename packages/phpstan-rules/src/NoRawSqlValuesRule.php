<?php

declare(strict_types=1);

namespace LombokClarion\PHPStanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\Encapsed;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The AST-level big brother of Console\BuiltIn\TokenScanner (§7): fails static
 * analysis when a value reaches PDO::query()/prepare()/exec() via string
 * concatenation, interpolation ("... $id ..."), or sprintf().
 *
 * @implements Rule<MethodCall>
 */
final class NoRawSqlValuesRule implements Rule
{
    private const METHODS = ['query', 'prepare', 'exec'];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier
            || !in_array($node->name->toLowerString(), self::METHODS, true)
            || $node->getArgs() === []) {
            return [];
        }

        $arg = $node->getArgs()[0]->value;

        $violation = match (true) {
            $arg instanceof Concat => 'string concatenation',
            $arg instanceof Encapsed => 'variable interpolation',
            $arg instanceof FuncCall
                && $arg->name instanceof Node\Name
                && in_array($arg->name->toLowerString(), ['sprintf', 'vsprintf'], true)
                => 'sprintf/vsprintf',
            default => null,
        };

        if ($violation === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'SQL passed to %s() is built via %s. Every value must be a bound '
                . 'parameter — use LombokClarion\Persistence\QueryBuilder or a '
                . 'prepared statement with ? placeholders.',
                $node->name->toString(),
                $violation
            ))->identifier('lombokclarion.rawSqlValue')->build(),
        ];
    }
}
