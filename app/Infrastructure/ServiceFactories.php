<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Widget\CreateWidget;
use App\Domain\Widget\CreateWidgetHandler;
use App\Domain\Widget\ListWidgets;
use App\Domain\Widget\ListWidgetsHandler;
use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\EventBus;
use LombokClarion\Bus\QueryBus;
use LombokClarion\Container\ContainerInterface;
use LombokClarion\Security\CsrfTokenManager;
use LombokClarion\Security\InMemoryRateLimitStore;
use LombokClarion\Security\SecurityConfig;
use LombokClarion\View\AssetManifest;
use LombokClarion\View\Theme;
use LombokClarion\View\ViewEngine;

/**
 * ContainerCompiler cannot bake a raw Closure into services.compiled.php,
 * so every closure binding in bootstrap/services.php is written as an
 * array callable pointing here — [ServiceFactories::class, 'commandBus'],
 * etc. — which the compiler CAN emit as a plain static call with zero
 * runtime reflection (see LombokClarion\Container\ContainerCompiler).
 */
final class ServiceFactories
{
    public static function commandBus(ContainerInterface $c): CommandBus
    {
        $bus = new CommandBus($c);
        $bus->register(CreateWidget::class, CreateWidgetHandler::class);
        return $bus;
    }

    public static function queryBus(ContainerInterface $c): QueryBus
    {
        $bus = new QueryBus($c);
        $bus->register(ListWidgets::class, ListWidgetsHandler::class);
        return $bus;
    }

    public static function eventBus(ContainerInterface $c): EventBus
    {
        return new EventBus($c);
    }

    public static function securityConfig(ContainerInterface $c): SecurityConfig
    {
        return new SecurityConfig();
    }

    public static function csrfTokenManager(ContainerInterface $c): CsrfTokenManager
    {
        return new CsrfTokenManager(getenv('APP_KEY') ?: 'dev-secret-change-me');
    }

    public static function rateLimitStore(ContainerInterface $c): InMemoryRateLimitStore
    {
        return new InMemoryRateLimitStore();
    }

    public static function viewEngine(ContainerInterface $c): ViewEngine
    {
        return new ViewEngine(
            templatesPath: dirname(__DIR__, 2) . '/resources/views',
            cachePath: dirname(__DIR__, 2) . '/storage/views',
        );
    }

    public static function theme(ContainerInterface $c): Theme
    {
        return new Theme(getenv('THEME_STYLE') ?: 'resonant-stark');
    }

    public static function assetManifest(ContainerInterface $c): AssetManifest
    {
        return AssetManifest::fromFile(dirname(__DIR__, 2) . '/storage/assets.manifest.php');
    }
}
