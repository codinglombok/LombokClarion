<?php

declare(strict_types=1);

namespace LombokClarion\LaravelFlavor;

use LombokClarion\Auth\AuthManager;
use LombokClarion\Facades\Facade;

/**
 * Lives in laravel-flavor, not lombokclarion/facades: the base facades
 * package depends only on the container, and an Auth facade would drag
 * lombokclarion/auth into every facades consumer. The flavor preset is
 * the aggregation point that already depends on both.
 *
 * @method static ?\LombokClarion\Auth\Authenticatable attempt(string $identifier, string $password)
 * @method static ?\LombokClarion\Auth\Authenticatable user()
 * @method static bool check()
 * @method static string login(\LombokClarion\Auth\Authenticatable $user)
 * @method static bool logout(?string $token = null)
 */
final class Auth extends Facade
{
    protected static function accessor(): string
    {
        return AuthManager::class;
    }
}
