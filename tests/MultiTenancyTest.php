<?php

declare(strict_types=1);

use LombokClarion\Container\Container;
use LombokClarion\Http\HeaderTenantResolver;
use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\RequestContext;
use LombokClarion\Http\ResolveTenant;
use LombokClarion\Http\Response;
use LombokClarion\Http\Tenant;
use LombokClarion\Http\TenantAwareConnection;
use LombokClarion\Routing\Kernel;
use LombokClarion\Routing\Router;

$tenantDb = [
    'acme' => new Tenant('acme', 'Acme Corp', 'acme_db'),
    'globex' => new Tenant('globex', 'Globex Inc', 'globex_db'),
];

test('HeaderTenantResolver returns tenant from X-Tenant-ID header', function () use ($tenantDb) {
    $resolver = new HeaderTenantResolver(fn (string $id) => $tenantDb[$id] ?? null);
    $request = new Request('GET', '/', headers: ['x-tenant-id' => 'acme']);
    $tenant = $resolver->resolve($request);
    assertSame('acme', $tenant->id);
    assertSame('Acme Corp', $tenant->name);
});

test('HeaderTenantResolver returns null when header is missing', function () use ($tenantDb) {
    $resolver = new HeaderTenantResolver(fn (string $id) => $tenantDb[$id] ?? null);
    $tenant = $resolver->resolve(new Request('GET', '/'));
    assertSame(null, $tenant);
});

test('HeaderTenantResolver returns null for unknown tenant ID', function () use ($tenantDb) {
    $resolver = new HeaderTenantResolver(fn (string $id) => $tenantDb[$id] ?? null);
    $request = new Request('GET', '/', headers: ['x-tenant-id' => 'unknown']);
    $tenant = $resolver->resolve($request);
    assertSame(null, $tenant);
});

final class Test_TenantEchoController
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function show(Request $request): Response
    {
        /** @var Tenant $tenant */
        $tenant = $this->context->get('tenant');
        return Response::json(['tenant_id' => $tenant->id, 'tenant_name' => $tenant->name]);
    }
}

test('ResolveTenant middleware binds tenant into RequestContext for downstream use', function () use ($tenantDb) {
    $container = new Container();
    $context = new RequestContext();
    $container->instance(RequestContext::class, $context);

    $resolver = new HeaderTenantResolver(fn (string $id) => $tenantDb[$id] ?? null);
    $mw = new ResolveTenant($resolver, $context);
    $container->instance(ResolveTenant::class, $mw);

    $router = new Router();
    $router->get('/dashboard', [Test_TenantEchoController::class, 'show'], [ResolveTenant::class]);

    $kernel = new Kernel($container, $router);

    // With valid tenant header
    $response = $kernel->handle(new Request('GET', '/dashboard', headers: ['x-tenant-id' => 'globex']));
    assertSame(200, $response->status);
    $data = json_decode($response->body, true);
    assertSame('globex', $data['tenant_id']);
    assertSame('Globex Inc', $data['tenant_name']);
});

test('ResolveTenant middleware returns 400 when tenant cannot be identified', function () use ($tenantDb) {
    $context = new RequestContext();
    $resolver = new HeaderTenantResolver(fn (string $id) => $tenantDb[$id] ?? null);
    $mw = new ResolveTenant($resolver, $context);

    $response = $mw->handle(
        new Request('GET', '/dashboard'),
        fn ($r) => Response::text('should not reach here')
    );

    assertSame(400, $response->status);
    assertTrue(str_contains($response->body, 'Tenant could not be identified'));
});

test('routes without ResolveTenant middleware work without a tenant (no implicit tenancy mode)', function () {
    $container = new Container();
    $container->instance(RequestContext::class, new RequestContext());

    $router = new Router();
    $router->get('/public', [Test_TenantEchoController::class, 'show']); // NO tenant middleware

    $kernel = new Kernel($container, $router);
    // This will hit the controller without a tenant in context — that's the app's
    // choice, not the framework's concern. No 400, no implicit fallback.
    $response = $kernel->handle(new Request('GET', '/public'));
    assertSame(200, $response->status);
    $data = json_decode($response->body, true);
    assertSame(null, $data['tenant_id']);
});

test('TenantAwareConnection builds DSN from tenant databaseName', function () {
    $dir = sys_get_temp_dir() . '/lc_tenant_' . uniqid();
    mkdir($dir);
    // Create two separate SQLite databases.
    $tenant = new Tenant('t1', 'T1', "$dir/t1.sqlite");
    $pdo = TenantAwareConnection::forTenant($tenant, 'sqlite:{database}');
    $pdo->exec('CREATE TABLE ping (id INTEGER)');
    assertTrue(true); // no exception = correct DSN routing

    $tenant2 = new Tenant('t2', 'T2', "$dir/t2.sqlite");
    $pdo2 = TenantAwareConnection::forTenant($tenant2, 'sqlite:{database}');
    // t2's database must not have the table from t1 — proves isolation.
    $tables = $pdo2->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
    assertSame(0, count($tables));

    unlink("$dir/t1.sqlite");
    unlink("$dir/t2.sqlite");
    rmdir($dir);
});

test('TenantAwareConnection throws when no tenant or no databaseName', function () {
    assertThrows(RuntimeException::class, fn () => TenantAwareConnection::forTenant(null, 'sqlite:{database}'));
    assertThrows(RuntimeException::class, fn () => TenantAwareConnection::forTenant(
        new Tenant('x', 'X', null), 'sqlite:{database}'
    ));
});
