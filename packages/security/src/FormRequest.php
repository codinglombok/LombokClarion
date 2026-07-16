<?php

declare(strict_types=1);

namespace LombokClarion\Security;

use LombokClarion\Http\Request;
use LombokClarion\Security\Exceptions\ValidationException;

/**
 * Mass-assignment is structurally impossible: validated() only ever
 * returns keys explicitly listed in rules(), so a handler that does
 * `new Entity(...$validated)` can never receive a field the developer
 * didn't declare — there is no blocklist to keep in sync.
 */
abstract class FormRequest
{
    /**
     * @return array<string, list<'required'|'string'|'int'|'bool'|'email'>>
     */
    abstract public function rules(): array;

    /**
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validated(Request $request): array
    {
        $data = [];
        $errors = [];

        foreach ($this->rules() as $field => $fieldRules) {
            $value = $request->input($field);

            foreach ($fieldRules as $rule) {
                $error = $this->checkRule($field, $rule, $value);
                if ($error !== null) {
                    $errors[$field][] = $error;
                }
            }

            if ($value !== null && !isset($errors[$field])) {
                $data[$field] = $value;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    private function checkRule(string $field, string $rule, mixed $value): ?string
    {
        return match ($rule) {
            'required' => ($value === null || $value === '') ? "$field is required" : null,
            'string' => ($value !== null && !is_string($value)) ? "$field must be a string" : null,
            'int' => ($value !== null && !is_numeric($value)) ? "$field must be numeric" : null,
            'bool' => ($value !== null && !is_bool($value) && !in_array($value, ['0', '1', 0, 1, true, false], true)) ? "$field must be a boolean" : null,
            'email' => ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) ? "$field must be a valid email" : null,
            default => null,
        };
    }
}
