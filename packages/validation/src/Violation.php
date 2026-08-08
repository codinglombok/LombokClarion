<?php

declare(strict_types=1);

namespace LombokClarion\Validation;

/**
 * One failed constraint: a message KEY plus its parameters — never a
 * pre-rendered string. Rendering is the presenter's job (with a Translator
 * and the request's locale); keeping violations structural is what lets
 * the same failure become an Indonesian form message, an English API
 * error, or a log line without re-running validation.
 */
final class Violation
{
    /**
     * @param array<string, string|int> $params
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params,
    ) {
    }
}
