<?php

declare(strict_types=1);

namespace LombokClarion\Http;

use PDO;

/**
 * When tenancy maps to separate databases (the recommended isolation
 * pattern), this factory creates the correct PDO for the current tenant.
 * Typically wired as a request-scoped binding in services.php:
 *
 *   $container->bind(PDO::class, function (ContainerInterface $c) {
 *       $context = $c->get(RequestContext::class);
 *       $tenant = $context->get('tenant');
 *       return TenantAwareConnection::forTenant($tenant, $baseDsn);
 *   });
 *
 * Non-tenanted routes (which never run ResolveTenant middleware) still get
 * the default PDO — there's no implicit "fall through to the shared DB"
 * when a tenant is expected but missing; ResolveTenant already returned 400.
 */
final class TenantAwareConnection
{
    /**
     * @param string $baseDsn DSN template with {database} placeholder, e.g.
     *        "pgsql:host=localhost;dbname={database}" or "sqlite:{database}"
     */
    public static function forTenant(?Tenant $tenant, string $baseDsn): PDO
    {
        if ($tenant === null || $tenant->databaseName === null) {
            throw new \RuntimeException(
                'TenantAwareConnection::forTenant() called without a resolved ' .
                'tenant or without a tenant.databaseName. Ensure ResolveTenant ' .
                'middleware ran before any DB access on this route.'
            );
        }

        $dsn = str_replace('{database}', $tenant->databaseName, $baseDsn);
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
