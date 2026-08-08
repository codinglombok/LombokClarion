<?php

declare(strict_types=1);

namespace LombokClarion\PHPStanRules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The AST-level big brother of Console\BuiltIn\TokenScanner (§7): fails static
 * analysis when a value can reach PDO::query()/prepare()/exec() as anything
 * other than a bound parameter.
 *
 * Unlike a token scanner, this rule works on PHPStan's INFERRED TYPES rather
 * than on the syntactic shape of the argument. That difference matters twice:
 *
 *  - Precision. `'INSERT ... ' . 'VALUES (?, ?)'` is a concatenation, but every
 *    operand is a literal, so it folds to a constant string and carries no
 *    injection surface. Shape-matching flags it; type-checking does not.
 *
 *  - Reach. `$sql = 'SELECT ... ' . $id; $pdo->query($sql);` is NOT syntactically
 *    a concatenation at the call site, so shape-matching misses it entirely —
 *    a one-line bypass of the whole gate. PHPStan follows the variable, so the
 *    non-constant string is caught here.
 *
 * The invariant enforced: the first argument must either fold to a compile-time
 * constant string, or be assembled exclusively from sanctioned sources —
 * Identifier::validate()/quote() (identifiers can never be bound) or
 * TrustedDdl::mark() (reviewed DDL). Anything else is a finding.
 *
 * @implements Rule<MethodCall>
 */
final class NoRawSqlValuesRule implements Rule
{
    private const METHODS = ['query', 'prepare', 'exec'];

    /** class short name => allowed methods */
    private const SANITIZERS = [
        'Identifier' => ['validate', 'quote'],
        'TrustedDdl' => ['mark'],
    ];

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

        if ($this->isSafe($arg, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'SQL passed to %s() is not a constant string and is not built from validated '
                . 'identifiers. Every value must be a bound parameter — use '
                . 'LombokClarion\Persistence\QueryBuilder, a prepared statement with ? '
                . 'placeholders, or RawExpression. For schema statements, which cannot bind '
                . 'identifiers, mark the site with TrustedDdl::mark().',
                $node->name->toString()
            ))->identifier('lombokclarion.rawSqlValue')->build(),
        ];
    }

    /**
     * An expression is safe when every value it can hold is known at analysis
     * time, or when each dynamic part comes from a sanctioned source.
     */
    private function isSafe(Expr $expr, Scope $scope): bool
    {
        // Folds to one or more known literals => no injection surface at all.
        // This covers literal-only concatenation and conditional `.=` chains.
        if ($scope->getType($expr)->getConstantStrings() !== []) {
            return true;
        }

        if ($this->isSanitizerCall($expr)) {
            return true;
        }

        if ($expr instanceof Concat) {
            return $this->isSafe($expr->left, $scope) && $this->isSafe($expr->right, $scope);
        }

        // An expression is only as safe as its worst branch.
        if ($expr instanceof Expr\Ternary) {
            $then = $expr->if ?? $expr->cond;

            return $this->isSafe($then, $scope) && $this->isSafe($expr->else, $scope);
        }

        if ($expr instanceof Expr\Match_) {
            foreach ($expr->arms as $arm) {
                if (!$this->isSafe($arm->body, $scope)) {
                    return false;
                }
            }

            return true;
        }

        // "text $var text" — PHP-Parser 5 renamed Encapsed to InterpolatedString
        // but keeps the old name as a BC alias; match on the parent to survive both.
        if ($expr instanceof Node\Scalar\Encapsed) {
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\Scalar\EncapsedStringPart) {
                    continue;
                }
                if (!$this->isSafe($part, $scope)) {
                    return false;
                }
            }

            return true;
        }

        if ($expr instanceof FuncCall
            && $expr->name instanceof Node\Name
            && in_array($expr->name->toLowerString(), ['sprintf', 'vsprintf', 'implode', 'join'], true)) {
            foreach ($expr->getArgs() as $arg) {
                if (!$this->isSafe($arg->value, $scope)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function isSanitizerCall(Expr $expr): bool
    {
        if (!$expr instanceof StaticCall
            || !$expr->class instanceof Node\Name
            || !$expr->name instanceof Node\Identifier) {
            return false;
        }

        $parts = $expr->class->getParts();
        $shortName = end($parts);
        $method = $expr->name->toLowerString();

        foreach (self::SANITIZERS as $class => $methods) {
            if ($shortName === $class && in_array($method, $methods, true)) {
                return true;
            }
        }

        return false;
    }
}
