<?php

declare(strict_types=1);

namespace LombokClarion\PHPStanRules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces the §3 hard rule at analysis time: files under app/Domain/** may not
 * import LombokClarion\* (the same check bin/check-domain-boundary.php runs in CI,
 * now surfaced inside editors/PHPStan pipelines).
 *
 * @implements Rule<Use_>
 */
final class DomainBoundaryRule implements Rule
{
    public function getNodeType(): string
    {
        return Use_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!str_contains(str_replace('\\', '/', $scope->getFile()), '/app/Domain/')) {
            return [];
        }

        $errors = [];
        foreach ($node->uses as $use) {
            $name = $use->name->toString();
            if (str_starts_with($name, 'LombokClarion\\')) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'app/Domain/** must not import framework classes; found "use %s". '
                    . 'The domain layer depends only on plain PHP and other Domain classes (§3).',
                    $name
                ))->identifier('lombokclarion.domainBoundary')->build();
            }
        }

        return $errors;
    }
}
