<?php

declare(strict_types=1);

/**
 * Lightweight PSR-4 autoloader for local development/testing without network
 * access to Packagist. In a real deployment this is replaced entirely by
 * Composer's generated autoloader (see composer.json "autoload" blocks in
 * each package) — this file exists only so the framework can be exercised
 * and tested inside this sandbox.
 */

spl_autoload_register(function (string $class): void {
    $map = [
        'LombokClarion\\Container\\' => __DIR__ . '/packages/container/src/',
        'LombokClarion\\Http\\'      => __DIR__ . '/packages/http/src/',
        'LombokClarion\\Routing\\'   => __DIR__ . '/packages/routing/src/',
        'LombokClarion\\Bus\\'       => __DIR__ . '/packages/bus/src/',
        'LombokClarion\\Config\\'    => __DIR__ . '/packages/config/src/',
        'LombokClarion\\Persistence\\' => __DIR__ . '/packages/persistence/src/',
        'LombokClarion\\View\\'      => __DIR__ . '/packages/view/src/',
        'LombokClarion\\Console\\'   => __DIR__ . '/packages/console/src/',
        'LombokClarion\\Testing\\'   => __DIR__ . '/packages/testing/src/',
        'LombokClarion\\Security\\'  => __DIR__ . '/packages/security/src/',
        'LombokClarion\\ActiveRecord\\' => __DIR__ . '/packages/active-record/src/',
        'LombokClarion\\Facades\\'  => __DIR__ . '/packages/facades/src/',
        'App\\'                      => __DIR__ . '/app/',
    ];

    foreach ($map as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
            return;
        }
    }
});
