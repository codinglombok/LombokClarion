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
    // Stage 10: named connections, read as typed properties
    // ($config->connections->reporting->dsn) rather than array lookups.
    // These are DECLARATIONS, not open connections — ConnectionManager
    // opens nothing until something asks for it, so an unused `reporting`
    // entry costs one string in the compiled config and nothing else.
    'connections' => [
        'default' => [
            'driver' => ['type' => 'string', 'env' => 'DB_DRIVER', 'default' => 'sqlite'],
            'dsn' => ['type' => 'string', 'env' => 'DB_DSN', 'default' => 'sqlite::memory:'],
            // Read/write split: empty means no split, and read() then returns
            // the very same PDO write() does.
            'read_dsn' => ['type' => 'string', 'env' => 'DB_READ_DSN', 'default' => ''],
        ],
        'reporting' => [
            'driver' => ['type' => 'string', 'env' => 'DB_REPORTING_DRIVER', 'default' => 'sqlite'],
            'dsn' => ['type' => 'string', 'env' => 'DB_REPORTING_DSN', 'default' => 'sqlite::memory:'],
            'read_dsn' => ['type' => 'string', 'env' => 'DB_REPORTING_READ_DSN', 'default' => ''],
        ],
        'tenants' => [
            'driver' => ['type' => 'string', 'env' => 'DB_TENANT_DRIVER', 'default' => 'sqlite'],
            // Must contain {database}; resolved per tenant, never opened by name.
            'dsn' => ['type' => 'string', 'env' => 'DB_TENANT_DSN', 'default' => 'sqlite:{database}'],
        ],
    ],
];
