<?php

declare(strict_types=1);

use LombokClarion\Auth\Authenticate;
use LombokClarion\Console\BuiltIn\AuditSecurityCommand;
use LombokClarion\Console\BuiltIn\AuditSqlCommand;
use LombokClarion\Console\BuiltIn\MigrateCommand;
use LombokClarion\Console\BuiltIn\OptimizeCommand;
use LombokClarion\Console\BuiltIn\WorkCommand;
use LombokClarion\Console\ConsoleKernel;
use LombokClarion\Container\Container;
use LombokClarion\Container\ContainerInterface;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Security\SecurityHeaders;
use LombokClarion\Security\ValidateCsrf;

return function (Container $container): ConsoleKernel {
    $manifest = require __DIR__ . '/migrations.php';
    $seeders = require __DIR__ . '/seeders.php';

    $container->singleton(\App\Console\UserCreateCommand::class, function (ContainerInterface $c) {
        return new \App\Console\UserCreateCommand(
            $c->get(PDO::class),
            $c->get(\LombokClarion\Security\PasswordHasher::class)
        );
    });

    $container->singleton(\LombokClarion\Console\BuiltIn\MigrateRollbackCommand::class, function (ContainerInterface $c) use ($manifest) {
        return new \LombokClarion\Console\BuiltIn\MigrateRollbackCommand($c->get(ConnectionManager::class), $manifest);
    });

    $container->singleton(MigrateCommand::class, function (ContainerInterface $c) use ($manifest) {
        // The command takes the manager, not a pre-built runner: --connection=
        // can only mean something if the command is still free to choose the
        // connection at run() time. See MigrateCommand's class docblock.
        return new MigrateCommand($c->get(ConnectionManager::class), $manifest);
    });

    $container->singleton(\LombokClarion\Console\BuiltIn\MigrateStatusCommand::class, function (ContainerInterface $c) use ($manifest) {
        return new \LombokClarion\Console\BuiltIn\MigrateStatusCommand($c->get(ConnectionManager::class), $manifest);
    });

    // Target directory and namespace are configuration, not discovery — the
    // command has no way to guess where this app keeps its migrations.
    $container->singleton(\LombokClarion\Console\BuiltIn\MakeMigrationCommand::class, function () {
        return new \LombokClarion\Console\BuiltIn\MakeMigrationCommand(
            targetDirectory: dirname(__DIR__) . '/app/Infrastructure/Persistence/Migrations',
            namespace: 'App\\Infrastructure\\Persistence\\Migrations',
        );
    });

    $container->singleton(\LombokClarion\Console\BuiltIn\SeedCommand::class, function (ContainerInterface $c) use ($seeders) {
        // Same reasoning as MigrateCommand: the manager, not a pre-built
        // runner, so --connection= is still free to choose at run() time.
        // defaultSeed is configuration — a fixed integer so that a plain
        // `seed` with no flags is itself reproducible.
        return new \LombokClarion\Console\BuiltIn\SeedCommand(
            $c->get(ConnectionManager::class),
            $seeders,
            defaultSeed: 1,
        );
    });

    $container->singleton(\LombokClarion\Console\BuiltIn\SeedStatusCommand::class, function (ContainerInterface $c) use ($seeders) {
        return new \LombokClarion\Console\BuiltIn\SeedStatusCommand($c->get(ConnectionManager::class), $seeders);
    });

    // Target directory and namespace are configuration, not discovery — same
    // as MakeMigrationCommand.
    $container->singleton(\LombokClarion\Console\BuiltIn\MakeSeederCommand::class, function () {
        return new \LombokClarion\Console\BuiltIn\MakeSeederCommand(
            targetDirectory: dirname(__DIR__) . '/app/Infrastructure/Persistence/Seeders',
            namespace: 'App\\Infrastructure\\Persistence\\Seeders',
        );
    });

    $container->singleton(AuditSqlCommand::class, fn () => new AuditSqlCommand());

    $container->singleton(WorkCommand::class, function (ContainerInterface $c) {
        $pdo = $c->get(PDO::class);
        $bus = $c->get(\LombokClarion\Bus\CommandBus::class);
        $store = new \LombokClarion\Bus\Queue\DatabaseQueueStore($pdo);
        return new WorkCommand(new \LombokClarion\Bus\Queue\QueueWorker($bus, $store));
    });

    $container->singleton(AuditSecurityCommand::class, function (ContainerInterface $c) {
        $router = (require __DIR__ . '/routes.php')($c);
        return new AuditSecurityCommand(
            $router,
            globalMiddleware: [SecurityHeaders::class],
            csrfMiddlewareClass: ValidateCsrf::class,
            securityHeadersMiddlewareClass: SecurityHeaders::class,
            envFilePath: __DIR__ . '/../.env',
            authMiddlewareClass: Authenticate::class,
        );
    });

    $container->singleton(OptimizeCommand::class, function () {
        $servicesContainer = (require __DIR__ . '/services.php')();
        $root = dirname(__DIR__);
        return new OptimizeCommand(
            devContainer: $servicesContainer,
            servicesOutputPath: $root . '/storage/services.compiled.php',
            extraRootIds: [
                \App\Http\Controllers\WidgetController::class,
                \App\Http\Controllers\PageController::class,
                // Missing since Stage 9 — invisible while nothing loaded the
                // compiled container (finding #40's compounding effect: every
                // gap in an artifact nobody consumes is a gap nobody sees).
                \App\Http\Controllers\AuthController::class,
                \App\Domain\Widget\CreateWidgetHandler::class,
                \App\Domain\Widget\ListWidgetsHandler::class,
                \LombokClarion\Security\SecurityHeaders::class,
                \LombokClarion\Security\ValidateCsrf::class,
                \LombokClarion\Auth\Authenticate::class,
                \LombokClarion\Http\RequestContext::class,
            ],
            // A ConnectionManager holds (or lazily opens) live PDO handles, which
            // no compiled container can serialise — same reason PDO itself is
            // here. Both are bound at boot by services.php instead.
            externallyProvided: [PDO::class, ConnectionManager::class],
            configSchema: require $root . '/config/config.schema.php',
            configOutputPath: $root . '/storage/config.compiled.php',
            assets: [
                'lombok.min.css' => $root . '/resources/lombokcss/lombok.min.css',
                'quiet-editorial.css' => $root . '/resources/lombokcss/quiet-editorial.css',
                'lombok-charts.umd.min.js' => $root . '/resources/lombokcharts/lombok-charts.umd.min.js',
            ],
            publicAssetsDir: $root . '/public/assets',
            assetManifestPath: $root . '/storage/assets.manifest.php',
            router: (require __DIR__ . '/routes.php')($servicesContainer),
            routesOutputPath: $root . '/storage/routes.compiled.php',
        );
    });

    $console = new ConsoleKernel($container);
    $console->register(MigrateCommand::class);
    $console->register(\LombokClarion\Console\BuiltIn\MigrateRollbackCommand::class);
    $console->register(\LombokClarion\Console\BuiltIn\MigrateStatusCommand::class);
    $console->register(\LombokClarion\Console\BuiltIn\MakeMigrationCommand::class);
    $console->register(\LombokClarion\Console\BuiltIn\SeedCommand::class);
    $console->register(\LombokClarion\Console\BuiltIn\SeedStatusCommand::class);
    $console->register(\LombokClarion\Console\BuiltIn\MakeSeederCommand::class);
    $console->register(\App\Console\UserCreateCommand::class);
    $console->register(AuditSqlCommand::class);
    $console->register(AuditSecurityCommand::class);
    $console->register(OptimizeCommand::class);
    $console->register(WorkCommand::class);

    return $console;
};
