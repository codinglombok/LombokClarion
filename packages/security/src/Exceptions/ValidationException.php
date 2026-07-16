<?php

declare(strict_types=1);

namespace LombokClarion\Security\Exceptions;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors field => messages
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed: ' . implode('; ', array_merge(...array_values($errors))));
    }
}
