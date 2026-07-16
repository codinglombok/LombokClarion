<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use LombokClarion\Routing\Adapters\FpmAdapter;
use LombokClarion\Routing\Kernel;
use LombokClarion\Security\SecurityHeaders;
use LombokClarion\View\StaticAssetsMiddleware;

$container = (require __DIR__ . '/../bootstrap/services.php')();
$router = (require __DIR__ . '/../bootstrap/routes.php')($container);

$kernel = new Kernel($container, $router, globalMiddleware: [
    // Serves /assets/* with Cache-Control: immutable when running under
    // `php -S`; in real deployments the web server handles /assets/*
    // directly and this middleware never matches.
    new StaticAssetsMiddleware(__DIR__ . '/assets'),
    SecurityHeaders::class,
]);
$adapter = new FpmAdapter($kernel);
$adapter->run();
