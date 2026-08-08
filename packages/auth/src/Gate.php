<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

use LombokClarion\Auth\Exceptions\AuthException;

/**
 * Abilities are registered by hand:
 *
 *   $gate->define('post.edit', PostPolicy::class);
 *
 * There is no attribute scanning and no directory crawl to discover policies —
 * §2's rule, applied here for a specific reason: an authorization rule that
 * appears because a file was found in the right folder can also disappear when
 * a file moves, and it does so silently. bootstrap/ lists every ability this
 * application has; that list is greppable, reviewable in a diff, and cannot
 * change because of a filename.
 *
 * Policies are resolved through a callable factory (the container, in the app)
 * rather than instantiated here, so a policy can have dependencies of its own.
 */
final class Gate
{
    /** @var array<string, class-string> */
    private array $abilities = [];

    /** @var callable(class-string): Policy */
    private $resolver;

    /**
     * @param callable(class-string): Policy $resolver
     */
    public function __construct(callable $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @param class-string $policyClass
     */
    public function define(string $ability, string $policyClass): void
    {
        if (isset($this->abilities[$ability])) {
            throw new AuthException(sprintf(
                'Ability "%s" is already defined by %s. Redefining it would make the '
                . 'effective rule depend on which bootstrap file ran last — declare it once.',
                $ability,
                $this->abilities[$ability]
            ));
        }

        $this->abilities[$ability] = $policyClass;
    }

    /**
     * An undefined ability is an error, not a denial.
     *
     * Returning false here would be the dangerous kind of convenient: a typo in
     * `$gate->allows('post.edti', ...)` would read as "denied", the tests would
     * pass, and the bug would only surface as a user who cannot do their job —
     * or, with the check inverted somewhere, as one who can do everything. Fail
     * loudly on the unknown.
     */
    public function allows(string $ability, Authenticatable $user, mixed $subject = null): bool
    {
        if (!isset($this->abilities[$ability])) {
            throw new AuthException(sprintf(
                'Ability "%s" is not defined. Register it with $gate->define(%s, SomePolicy::class) '
                . 'in bootstrap. Unknown abilities are an error rather than a silent denial, so a '
                . 'typo cannot masquerade as an authorization rule.',
                $ability,
                var_export($ability, true)
            ));
        }

        $policy = ($this->resolver)($this->abilities[$ability]);

        return $policy->allows($ability, $user, $subject);
    }

    public function denies(string $ability, Authenticatable $user, mixed $subject = null): bool
    {
        return !$this->allows($ability, $user, $subject);
    }

    public function has(string $ability): bool
    {
        return isset($this->abilities[$ability]);
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return array_keys($this->abilities);
    }
}
