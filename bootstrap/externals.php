<?php

declare(strict_types=1);

use LombokClarion\Persistence\ConnectionConfig;
use LombokClarion\Persistence\ConnectionManager;

/**
 * The EXTERNALLY-PROVIDED instances: live handles that no compiled
 * container can serialize (see `externallyProvided` in bootstrap/console.php).
 * Returned as id => instance so both boot paths bind the exact same objects:
 *
 *   - dev boot: bootstrap/services.php requires this and instance()s them
 *     into the live container before adding the compilable bindings;
 *   - optimized boot: public/index.php instance()s them into the
 *     CompiledContainer loaded from storage/services.compiled.php.
 *
 * One source of truth on purpose. Finding #40 (AUDIT-TRAIL) was the compiled
 * container being produced and verified but never LOADED — and the fix would
 * rot instantly if the externals wiring were duplicated per path.
 *
 * @return array<class-string, object>
 */
return function (): array {
    $dbPath = getenv('DB_PATH') ?: (__DIR__ . '/../storage/database.sqlite');
    if (!is_dir(dirname($dbPath)) && $dbPath !== ':memory:') {
        mkdir(dirname($dbPath), 0775, true);
    }
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Default is `provided`: adopt the PDO above, never open a second handle
    // to the same file. `reporting` stays lazy; `tenants` is a {database}
    // template, unusable by name on purpose (Stage 10).
    $manager = new ConnectionManager([
        'default' => ConnectionConfig::provided('sqlite', $pdo),
        'reporting' => ConnectionConfig::dsn(
            'sqlite',
            'sqlite:' . (getenv('DB_REPORTING_PATH') ?: (__DIR__ . '/../storage/reporting.sqlite'))
        ),
        'tenants' => ConnectionConfig::template(
            'sqlite',
            getenv('DB_TENANT_DSN') ?: ('sqlite:' . __DIR__ . '/../storage/tenants/{database}.sqlite')
        ),
    ], default: 'default');

    return [
        PDO::class => $pdo,
        ConnectionManager::class => $manager,
    ];
};
