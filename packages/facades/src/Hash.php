<?php

declare(strict_types=1);

namespace LombokClarion\Facades;

use LombokClarion\Security\PasswordHasher;

/**
 * @method static string hash(string $plaintext)
 * @method static bool verify(string $plaintext, string $hash)
 */
final class Hash extends Facade
{
    protected static function accessor(): string
    {
        return PasswordHasher::class;
    }
}
