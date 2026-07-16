<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * Declared per-route or per-group, never applied globally by convention
 * (same principle as CSRF middleware). Routes that don't need tenancy
 * simply don't include this middleware — there's no "tenancy mode" to
 * toggle on or off at the framework level (§10/§11).
 *
 * When a tenant IS resolved, it's bound as an instance in RequestContext
 * so any downstream controller/handler/repository that needs it can
 * receive it via injection — request-scoped, explicit, zero global state.
 */
final class ResolveTenant implements Middleware
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly RequestContext $context,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant === null) {
            return Response::json(['error' => 'Tenant could not be identified'], 400);
        }

        $this->context->set('tenant', $tenant);

        return $next($request->withAttributes(['_tenant_id' => $tenant->id]));
    }
}
