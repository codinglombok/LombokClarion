<?php

declare(strict_types=1);

use App\Domain\Widget\WidgetRepositoryInterface;
use App\Infrastructure\Persistence\SqlWidgetRepository;
use App\Infrastructure\ServiceFactories;
use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\EventBus;
use LombokClarion\Bus\QueryBus;
use LombokClarion\Container\Container;
use LombokClarion\Security\Argon2idPasswordHasher;
use LombokClarion\Security\CsrfTokenManager;
use LombokClarion\Security\InMemoryRateLimitStore;
use LombokClarion\Security\PasswordHasher;
use LombokClarion\Security\RateLimitStore;
use LombokClarion\Security\SecurityConfig;
use LombokClarion\View\AssetManifest;
use LombokClarion\View\Theme;
use LombokClarion\View\ViewEngine;

/**
 * Returns a fully-wired dev-mode Container. This same function is used to
 * boot the app directly (uncompiled) in local dev, AND as the source of
 * truth `lombokclarion optimize` reads from to produce
 * services.compiled.php (see bin/lombokclarion's `optimize` wiring).
 */
return function (): Container {
    $container = new Container();

    $dbPath = getenv('DB_PATH') ?: (__DIR__ . '/../storage/database.sqlite');
    if (!is_dir(dirname($dbPath)) && $dbPath !== ':memory:') {
        mkdir(dirname($dbPath), 0775, true);
    }
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $container->instance(PDO::class, $pdo);

    // --- Domain bindings -----------------------------------------------
    $container->bind(WidgetRepositoryInterface::class, SqlWidgetRepository::class);

    // --- Bus -------------------------------------------------------------
    $container->singleton(CommandBus::class, [ServiceFactories::class, 'commandBus']);
    $container->singleton(QueryBus::class, [ServiceFactories::class, 'queryBus']);
    $container->singleton(EventBus::class, [ServiceFactories::class, 'eventBus']);

    // --- Security ---------------------------------------------------------
    $container->singleton(SecurityConfig::class, [ServiceFactories::class, 'securityConfig']);
    $container->bind(PasswordHasher::class, Argon2idPasswordHasher::class);
    $container->singleton(CsrfTokenManager::class, [ServiceFactories::class, 'csrfTokenManager']);
    $container->singleton(RateLimitStore::class, [ServiceFactories::class, 'rateLimitStore']);
    $container->bind(InMemoryRateLimitStore::class, [ServiceFactories::class, 'rateLimitStore']);

    // --- View / theme / assets ---------------------------------------------
    $container->singleton(ViewEngine::class, [ServiceFactories::class, 'viewEngine']);
    $container->singleton(Theme::class, [ServiceFactories::class, 'theme']);
    $container->singleton(AssetManifest::class, [ServiceFactories::class, 'assetManifest']);

    return $container;
};
