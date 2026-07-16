<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => ['type' => 'string', 'env' => 'APP_ENV', 'default' => 'local'],
        'debug' => ['type' => 'bool', 'env' => 'APP_DEBUG', 'default' => true],
        'key' => ['type' => 'string', 'env' => 'APP_KEY', 'default' => 'dev-secret-change-me'],
    ],
    'theme' => [
        'style' => ['type' => 'string', 'env' => 'THEME_STYLE', 'default' => 'resonant-stark'],
    ],
    'database' => [
        'driver' => ['type' => 'string', 'env' => 'DB_DRIVER', 'default' => 'sqlite'],
        'path' => ['type' => 'string', 'env' => 'DB_PATH', 'default' => ':memory:'],
    ],
];
