<?php

declare(strict_types=1);

use LombokClarion\Console\BuiltIn\AuditSecurityCommand;
use LombokClarion\Console\BuiltIn\AuditSqlCommand;
use LombokClarion\Console\BuiltIn\MigrateCommand;
use LombokClarion\Console\BuiltIn\OptimizeCommand;
use LombokClarion\Console\BuiltIn\WorkCommand;
use LombokClarion\Console\ConsoleKernel;
use LombokClarion\Container\Container;
use LombokClarion\Container\ContainerInterface;
use LombokClarion\Persistence\MigrationRunner;
use LombokClarion\Persistence\SchemaBuilder;
use LombokClarion\Security\SecurityHeaders;
use LombokClarion\Security\ValidateCsrf;

return function (Container $container): ConsoleKernel {
    $manifest = require __DIR__ . '/migrations.php';

    $container->singleton(MigrateCommand::class, function (ContainerInterface $c) use ($manifest) {
        $pdo = $c->get(PDO::class);
        $schema = new SchemaBuilder($pdo, 'sqlite');
        return new MigrateCommand(new MigrationRunner($pdo, $schema, 'sqlite'), $manifest);
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
                \App\Domain\Widget\CreateWidgetHandler::class,
                \App\Domain\Widget\ListWidgetsHandler::class,
                \LombokClarion\Security\SecurityHeaders::class,
                \LombokClarion\Security\ValidateCsrf::class,
            ],
            externallyProvided: [PDO::class],
            configSchema: require $root . '/config/config.schema.php',
            configOutputPath: $root . '/storage/config.compiled.php',
            assets: [
                'lombok.min.css' => $root . '/resources/lombokcss/lombok.min.css',
                'quiet-editorial.css' => $root . '/resources/lombokcss/quiet-editorial.css',
                'lombok-charts.umd.min.js' => $root . '/resources/lombokcharts/lombok-charts.umd.min.js',
            ],
            publicAssetsDir: $root . '/public/assets',
            assetManifestPath: $root . '/storage/assets.manifest.php',
        );
    });

    $console = new ConsoleKernel($container);
    $console->register(MigrateCommand::class);
    $console->register(AuditSqlCommand::class);
    $console->register(AuditSecurityCommand::class);
    $console->register(OptimizeCommand::class);
    $console->register(WorkCommand::class);

    return $console;
};
