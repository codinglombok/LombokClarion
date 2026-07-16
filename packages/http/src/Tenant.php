<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * Tenancy is a request-scoped container binding pattern (§11), not a
 * framework mode you toggle. A Tenant is resolved per request by a
 * TenantResolver middleware and bound into RequestContext. Application
 * code that needs the current tenant receives it via typed injection
 * (RequestContext or directly as a Tenant constructor parameter bound
 * per request), never via a static `Tenant::current()`.
 */
final class Tenant
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $databaseName = null,
    ) {
    }
}
