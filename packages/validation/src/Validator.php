<?php

declare(strict_types=1);

namespace LombokClarion\Validation;

/**
 * Walks a rules map over an input array. Two properties carry the weight:
 *
 * WHITELIST OUTPUT: the returned array contains ONLY fields named in the
 * rules — unknown input keys are dropped, never passed through. This is
 * the same posture as ActiveRecord's $fillable: what reaches the DTO (and
 * from there the database) is what the rules declared, so a smuggled
 * `is_admin=1` in the POST body dies here, structurally.
 *
 * ALL VIOLATIONS, NOT FIRST: validation collects every field's failures
 * before throwing, because a form that reveals its problems one submit at
 * a time is hostile, and an API client debugging one 422 per field is
 * wasting round-trips.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, Rule> $rules
     * @return array<string, mixed> canonicalized, whitelisted values
     * @throws ValidationException
     */
    public function validate(array $data, array $rules): array
    {
        $validated = [];
        $violations = [];

        foreach ($rules as $field => $rule) {
            if (!$rule instanceof Rule) {
                throw new \InvalidArgumentException(sprintf(
                    'rules()["%s"] must be a Rule object, got %s. The typed vocabulary is the API — there is no string DSL.',
                    $field,
                    get_debug_type($rule)
                ));
            }

            $present = array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '';

            if (!$present) {
                if ($rule->isNullable()) {
                    $validated[$field] = null;
                    continue;
                }

                $violations[$field][] = new Violation('validation.required', []);
                continue;
            }

            [$canonical, $violation] = $rule->check($data[$field]);

            if ($violation !== null) {
                $violations[$field][] = $violation;
                continue;
            }

            $validated[$field] = $canonical;
        }

        if ($violations !== []) {
            throw new ValidationException($violations);
        }

        return $validated;
    }
}
