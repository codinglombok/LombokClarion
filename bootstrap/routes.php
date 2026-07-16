<?php

declare(strict_types=1);

use App\Http\Controllers\PageController;
use App\Http\Controllers\WidgetController;
use LombokClarion\Container\ContainerInterface;
use LombokClarion\Routing\Router;
use LombokClarion\Security\RateLimit;
use LombokClarion\Security\RateLimitStore;
use LombokClarion\Security\ValidateCsrf;

/**
 * @return Router
 */
return function (ContainerInterface $container): Router {
    $router = new Router();
    $rateLimitStore = $container->get(RateLimitStore::class);

    // --- HTML pages (starter kit) ---------------------------------------
    $router->get('/', [PageController::class, 'home']);
    $router->get('/widgets', [PageController::class, 'widgets']);
    $router->get('/dashboard', [PageController::class, 'dashboard']);
    $router->post('/widgets', [PageController::class, 'storeWidget'], [
        ValidateCsrf::class,
        RateLimit::perMinute(30, $rateLimitStore),
    ]);

    // --- JSON API ---------------------------------------------------------
    $router->group('/api', [], function (Router $r) use ($rateLimitStore) {
        $r->get('/widgets', [WidgetController::class, 'index']);
        $r->post('/widgets', [WidgetController::class, 'store'], [
            ValidateCsrf::class,
            RateLimit::perMinute(30, $rateLimitStore),
        ]);
    });

    return $router;
};
