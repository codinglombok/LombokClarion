<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * Simplest resolver: reads `X-Tenant-ID` header and looks up the tenant
 * from a registry (which can be a database-backed implementation injected
 * from the container). Shipped as the default; subdomain/path-prefix
 * resolvers are app-specific and follow the same TenantResolver contract.
 */
final class HeaderTenantResolver implements TenantResolver
{
    /**
     * @param callable(string): ?Tenant $lookup given a tenant ID, return
     *        the Tenant or null if not found. The callable is resolved from
     *        the container at bind-time, so it can close over a PDO or a
     *        TenantRepository without this class knowing about either.
     */
    public function __construct(
        private readonly mixed $lookup,
        private readonly string $headerName = 'x-tenant-id',
    ) {
    }

    public function resolve(Request $request): ?Tenant
    {
        $tenantId = $request->header($this->headerName);

        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        return ($this->lookup)($tenantId);
    }
}
