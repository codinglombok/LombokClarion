<?php

declare(strict_types=1);

namespace LombokClarion\LaravelFlavor;

use LombokClarion\Facades\Facade;
use LombokClarion\Persistence\ConnectionManager;

/**
 * Static access to the Stage 10 ConnectionManager. `DB::table('widgets')`
 * returns a QueryBuilder already paired with the right PDO and grammar;
 * `DB::connection('reporting')` reaches a named connection. Same caveat
 * as every facade: this is container resolution in a static coat, and it
 * is banned from app/Domain/** like the rest of the flavor.
 *
 * @method static \LombokClarion\Persistence\QueryBuilder table(string $table, ?string $connection = null)
 * @method static \PDO connection(?string $name = null)
 * @method static \PDO read(?string $name = null)
 * @method static \PDO write(?string $name = null)
 */
final class DB extends Facade
{
    protected static function accessor(): string
    {
        return ConnectionManager::class;
    }
}
