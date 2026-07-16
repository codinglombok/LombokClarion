<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * Implementors decide HOW a tenant is identified from a request:
 *  - HeaderTenantResolver: reads X-Tenant-ID header (API-style)
 *  - SubdomainTenantResolver: reads tenant from subdomain
 *  - PathPrefixTenantResolver: reads /tenant-slug/...
 *
 * The framework ships a concrete HeaderTenantResolver; others are
 * app-specific. All are wired explicitly in services.php — there is no
 * auto-detection of "which strategy the app uses".
 */
interface TenantResolver
{
    /**
     * Returns null if the request carries no tenant identifier (e.g.
     * public-facing routes that don't require tenancy).
     */
    public function resolve(Request $request): ?Tenant;
}
