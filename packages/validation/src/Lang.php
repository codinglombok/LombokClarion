<?php

declare(strict_types=1);

namespace LombokClarion\Validation;

use RuntimeException;

/**
 * Explicit loader for the validation.* message catalogs that ship WITH this
 * package (resources/lang/*.php) instead of living in any one app.
 *
 * Wiring stays hand-written and greppable, same shape as every other catalog:
 *
 *   $t = new Translator('id', 'en');
 *   $t->addCatalog('en', Lang::catalog('en'));
 *   $t->addCatalog('id', Lang::catalog('id'));
 *   $t->addCatalog('id', require 'resources/lang/id.php'); // app keys — may override
 *
 * LOCALES is a hardcoded constant on purpose: no directory scanning at call
 * time, and the test suite asserts the constant matches the files on disk in
 * both directions (a new catalog file without a constant entry fails, and so
 * does a constant entry without a file). Unknown locale => RuntimeException,
 * not a silent empty catalog — same failure philosophy as the missing-
 * translator raw-key rendering in ValidationException.
 */
final class Lang
{
    /** @var list<string> */
    public const LOCALES = [
        'ar', 'bn', 'de', 'en', 'es', 'fa', 'fr', 'hi', 'id', 'it', 'ja', 'ko',
        'ms', 'nl', 'pl', 'pt', 'ru', 'sw', 'th', 'tr', 'uk', 'ur', 'vi', 'zh',
    ];

    /** @return array<string, string> */
    public static function catalog(string $locale): array
    {
        if (!in_array($locale, self::LOCALES, true)) {
            throw new RuntimeException(
                "lombokclarion/validation ships no catalog for locale \"{$locale}\". " .
                'Known locales: ' . implode(', ', self::LOCALES)
            );
        }

        /** @var array<string, string> */
        return require dirname(__DIR__) . "/resources/lang/{$locale}.php";
    }
}
