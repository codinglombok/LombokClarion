<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use LombokClarion\Routing\Adapters\FpmAdapter;
use LombokClarion\Container\CompiledContainer;
use LombokClarion\Http\Errors\ErrorHandler;
use LombokClarion\Routing\CompiledRouteMatcher;
use LombokClarion\Routing\Kernel;
use LombokClarion\Security\SecurityHeaders;
use LombokClarion\View\StaticAssetsMiddleware;

// Compiled container from `lombokclarion optimize`: when present, the
// definition graph loads as a plain PHP array — no binding closures run,
// no reflection ever (there is none in either path). The live externals
// (PDO, ConnectionManager) are bound on top from the SAME file the dev
// path uses. When absent — local dev — services.php builds everything.
// Before finding #40 this file always built the live container and the
// compiled one was produced, verified, and never loaded by anything.
$compiledServices = __DIR__ . '/../storage/services.compiled.php';

if (is_file($compiledServices)) {
    $container = CompiledContainer::fromFile($compiledServices);
    foreach ((require __DIR__ . '/../bootstrap/externals.php')() as $id => $instance) {
        $container->instance($id, $instance);
    }
} else {
    $container = (require __DIR__ . '/../bootstrap/services.php')();
}

$router = (require __DIR__ . '/../bootstrap/routes.php')($container);

// Compiled route index from `lombokclarion optimize`: when present, no
// regex is built at request time (Route compiles lazily and the matcher
// never asks it to). When absent — local dev — matching is live. The
// matcher throws loudly on a stale index rather than falling back.
$compiledRoutes = __DIR__ . '/../storage/routes.compiled.php';
$matcher = is_file($compiledRoutes)
    ? CompiledRouteMatcher::fromFile($compiledRoutes, $router)
    : null;

// Stage 17: uncaught exceptions become a logged, rendered 500 instead of a
// raw fatal, and 404/405 get the authored error pages. Resolved from the
// container so the app's own bindings decide the logger and the renderer.
// Debug output is gated inside ErrorHandler on APP_ENV, defaulting to
// production when APP_ENV is unset — see ServiceFactories::debugEnabled().
$kernel = new Kernel($container, $router, globalMiddleware: [
    // Serves /assets/* with Cache-Control: immutable when running under
    // `php -S`; in real deployments the web server handles /assets/*
    // directly and this middleware never matches.
    new StaticAssetsMiddleware(__DIR__ . '/assets'),
    SecurityHeaders::class,
], compiledMatcher: $matcher, errorHandler: $container->get(ErrorHandler::class));
$adapter = new FpmAdapter($kernel);
$adapter->run();
