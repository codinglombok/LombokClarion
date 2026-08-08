<?php

declare(strict_types=1);

namespace LombokClarion\Log;

/**
 * Removes secrets from log context before anything writes it down.
 *
 * WHY THIS IS IN THE CORE PATH AND NOT AN OPT-IN DECORATOR. Logs are the one
 * place a secret leaks without anyone doing anything wrong: nobody types
 * `log('password: hunter2')`, they type `log('login failed', $request->all())`
 * and the password rides along inside an array they never looked at. By the
 * time it is on disk it is also in the log shipper, the aggregator, and the
 * backup. Redaction that has to be remembered is redaction that will be
 * forgotten exactly once, which is all it takes — so {@see ChannelLogger}
 * applies this before handing a record to any handler, and there is no
 * constructor flag to turn it off.
 *
 * MATCHING IS ON KEY NAME, SUBSTRING, CASE-INSENSITIVE. Deliberately blunt.
 * A value-based heuristic ("looks like a JWT") misses the secrets it has not
 * seen and mangles ordinary text that happens to match; key names are what
 * developers actually control and what an auditor can read off this list. The
 * cost is over-redaction — a field called `password_reset_requested_at` gets
 * masked too. That trade is correct in this direction: a redacted timestamp is
 * an inconvenience, a logged credential is an incident.
 *
 * Nested arrays are walked, because the leak case IS the nested case.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /**
     * Substrings that mark a key as secret. Lowercase; matching lowercases the
     * key before comparing.
     *
     * @var list<string>
     */
    public const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'passwd',
        'secret',
        'token',
        'authorization',
        'auth',
        'credential',
        'api_key',
        'apikey',
        'private_key',
        'access_key',
        'session',
        'cookie',
        'csrf',
        'signature',
        'card_number',
        'cvv',
        'ssn',
        'pin',
    ];

    /** @var list<string> */
    private readonly array $sensitiveKeys;

    /**
     * @param list<string> $additionalKeys application-specific secret key
     *        names, added to the defaults rather than replacing them — an
     *        application that wants to redact `nik` should not have to restate
     *        `password` to do it, and should not be able to drop it by accident
     */
    public function __construct(array $additionalKeys = [])
    {
        $this->sensitiveKeys = [
            ...self::DEFAULT_SENSITIVE_KEYS,
            ...array_map(strtolower(...), $additionalKeys),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function redact(array $context): array
    {
        return $this->redactArray($context, 0);
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    private function redactArray(array $input, int $depth): array
    {
        // A cycle in the context array would otherwise recurse until the stack
        // dies — and a logger that crashes the process while reporting an
        // error is worse than the error.
        if ($depth > 16) {
            return ['[truncated]' => 'context nested deeper than 16 levels'];
        }

        $output = [];

        foreach ($input as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $output[$key] = self::MASK;
                continue;
            }

            $output[$key] = is_array($value)
                ? $this->redactArray($value, $depth + 1)
                : $value;
        }

        return $output;
    }

    private function isSensitive(string $key): bool
    {
        $lower = strtolower($key);

        foreach ($this->sensitiveKeys as $sensitive) {
            if (str_contains($lower, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
