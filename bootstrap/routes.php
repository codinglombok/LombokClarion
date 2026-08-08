<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\WidgetController;
use LombokClarion\Auth\Authenticate;
use LombokClarion\Auth\AuthManager;
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

    // --- auth -------------------------------------------------------------
    // The login route MUST carry both RateLimit and CSRF (§Stage 9.5):
    //   - RateLimit, because login is the one endpoint an attacker hammers;
    //   - CSRF, because it is a state-changing POST that sets a cookie, and a
    //     forged cross-site login is a real attack (it logs the victim into the
    //     attacker's account). Neither substitutes for the other.
    $router->post('/login', [AuthController::class, 'login'], [
        ValidateCsrf::class,
        RateLimit::perMinute(5, $rateLimitStore),
    ]);

    // Logout is declared --public in audit:security below: it is a mutating
    // route with no Authenticate middleware ON PURPOSE — logging out without a
    // valid session is a no-op, not an error, and requiring auth to un-auth is
    // a footgun. It still carries CSRF.
    $router->post('/logout', [AuthController::class, 'logout'], [
        ValidateCsrf::class,
    ]);

    // A protected read: 401 without a valid token, the user bound into context.
    // Authenticate is a CLASS-STRING here on purpose: the Kernel resolves
    // class-string middleware per request, AFTER the per-request
    // RequestContext is bound. `new Authenticate($container->get(...))` at
    // this point in the file would capture a boot-time context that no
    // request ever reads (finding #41) — eager construction is for
    // middleware carrying per-route VALUES (RateLimit::perMinute), not for
    // middleware carrying per-request STATE.
    $router->get('/me', [AuthController::class, 'me'], [
        Authenticate::class,
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
