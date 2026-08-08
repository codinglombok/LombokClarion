<?php

declare(strict_types=1);

namespace LombokClarion\Http;

use LombokClarion\Persistence\ConnectionConfig;
use LombokClarion\Persistence\ConnectionManager;
use PDO;
use RuntimeException;

/**
 * When tenancy maps to separate databases (the recommended isolation
 * pattern), this resolves the correct PDO for the current tenant.
 *
 * Before Stage 10 this class built the PDO itself with `new PDO($dsn)`.
 * It no longer does: it is a BRIDGE from the tenancy vocabulary (a Tenant,
 * which lives here) to the connection vocabulary (a named {database}
 * template, which lives in lombokclarion/persistence). How a connection is
 * opened, cached, error-moded and read/write split is the ConnectionManager's
 * business now, and a tenant connection gets all of it rather than a
 * hand-rolled subset. That is the point of routing tenants THROUGH the
 * manager instead of around it.
 *
 * This is also why lombokclarion/http now declares lombokclarion/persistence.
 * A bridge depends on both banks. The dependency was already latent: the old
 * implementation used ext-pdo without declaring that either.
 *
 * Typically wired as a request-scoped binding in services.php:
 *
 *   $container->bind(PDO::class, function (ContainerInterface $c) {
 *       $tenancy = $c->get(TenantAwareConnection::class);
 *       $tenant = $c->get(RequestContext::class)->get('tenant');
 *       return $tenancy->forTenantInstance($tenant);
 *   });
 *
 * Non-tenanted routes (which never run ResolveTenant middleware) still get
 * the default PDO — there's no implicit "fall through to the shared DB"
 * when a tenant is expected but missing; ResolveTenant already returned 400.
 */
final class TenantAwareConnection
{
    /**
     * @param string $template name of the ConnectionManager entry holding the
     *        {database} template DSN, e.g. 'tenants'
     */
    public function __construct(
        private readonly ConnectionManager $manager,
        private readonly string $template = 'tenants',
    ) {
    }

    public function forTenantInstance(?Tenant $tenant): PDO
    {
        return $this->manager->forDatabase($this->template, self::databaseNameOf($tenant));
    }

    /**
     * Static convenience for the case of one tenant template and no manager
     * on hand: it builds a throwaway manager around the DSN. Kept because the
     * signature is unchanged from Stage 9 and it is what the tenancy docs show.
     *
     * Prefer the instance form in an application — a manager held by the
     * container caches its PDOs across a request, and a throwaway cannot.
     *
     * @param string $baseDsn DSN template with {database} placeholder, e.g.
     *        "pgsql:host=localhost;dbname={database}" or "sqlite:{database}"
     */
    public static function forTenant(?Tenant $tenant, string $baseDsn): PDO
    {
        $database = self::databaseNameOf($tenant);

        $manager = new ConnectionManager(
            ['tenants' => ConnectionConfig::template(self::driverOf($baseDsn), $baseDsn)],
            default: 'tenants'
        );

        return $manager->forDatabase('tenants', $database);
    }

    private static function databaseNameOf(?Tenant $tenant): string
    {
        if ($tenant === null || $tenant->databaseName === null) {
            throw new RuntimeException(
                'TenantAwareConnection called without a resolved tenant or without a ' .
                'tenant.databaseName. Ensure ResolveTenant middleware ran before any DB ' .
                'access on this route.'
            );
        }

        return $tenant->databaseName;
    }

    private static function driverOf(string $baseDsn): string
    {
        $colon = strpos($baseDsn, ':');

        if ($colon === false || $colon === 0) {
            throw new RuntimeException(sprintf(
                'Cannot read a driver from DSN "%s" — expected "driver:...". Use the instance ' .
                'form with an explicit ConnectionConfig if the DSN is unusual.',
                $baseDsn
            ));
        }

        return substr($baseDsn, 0, $colon);
    }
}
