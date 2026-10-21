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
        'LombokClarion\\I18n\\'  => __DIR__ . '/packages/i18n/src/',
        'LombokClarion\\Auth\\'  => __DIR__ . '/packages/auth/src/',
        'LombokClarion\\LaravelFlavor\\' => __DIR__ . '/packages/laravel-flavor/src/',
        'LombokClarion\\Validation\\' => __DIR__ . '/packages/validation/src/',
        'LombokClarion\\Storage\\'   => __DIR__ . '/packages/storage/src/',
        'LombokClarion\\Log\\'       => __DIR__ . '/packages/log/src/',
        'LombokClarion\\Cache\\'     => __DIR__ . '/packages/cache/src/',
        'LombokClarion\\Session\\'   => __DIR__ . '/packages/session/src/',
        'LombokClarion\\Scheduler\\' => __DIR__ . '/packages/scheduler/src/',
        'LombokClarion\\Broadcasting\\' => __DIR__ . '/packages/broadcasting/src/',
        'LombokClarion\\Notification\\' => __DIR__ . '/packages/notification/src/',
        'LombokClarion\\WebSocket\\' => __DIR__ . '/packages/websocket/src/',
        'LombokClarion\\Mail\\'      => __DIR__ . '/packages/mail/src/',
        'LombokClarion\\CloudStorage\\' => __DIR__ . '/packages/cloud-storage/src/',
        'LombokClarion\\Inertia\\'   => __DIR__ . '/packages/inertia/src/',
        'LombokClarion\\Vite\\'      => __DIR__ . '/packages/vite/src/',
        'LombokClarion\\PHPStanRules\\' => __DIR__ . '/packages/phpstan-rules/src/',
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

    // lombokclarion/table-sheet ships multiple classes per file (classmap
    // layout in its own composer.json), so mirror that here: index every
    // class the src tree declares and require its file on demand.
    static $tableSheetClassMap = null;
    if ($tableSheetClassMap === null) {
        $tableSheetClassMap = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/packages/table-sheet/src', RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $before = get_declared_classes();
        $beforeInterfaces = get_declared_interfaces();
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            require_once $path;
            foreach (array_diff(get_declared_classes(), $before) as $c) {
                $tableSheetClassMap[$c] = $path;
                $before[] = $c;
            }
            foreach (array_diff(get_declared_interfaces(), $beforeInterfaces) as $c) {
                $tableSheetClassMap[$c] = $path;
                $beforeInterfaces[] = $c;
            }
        }
    }

    if (isset($tableSheetClassMap[$class])) {
        require_once $tableSheetClassMap[$class];
    }
});
