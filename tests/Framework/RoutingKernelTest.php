<?php

declare(strict_types=1);

namespace Tests\Framework;

use LombokClarion\Container\Container;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Http\Middleware;
use LombokClarion\Routing\Kernel;
use LombokClarion\Routing\Router;
use PHPUnit\Framework\TestCase;

final class GlobalHeaderMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request)->withHeader('X-Global', 'on');
    }
}

final class ShortCircuitMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return Response::text('blocked', 403);
    }
}

final class HomeController
{
    public function index(Request $request): Response
    {
        return Response::html('<h1>home</h1>');
    }
}

final class WidgetApiController
{
    public function show(Request $request): Response
    {
        return Response::json(['id' => $request->attribute('id')]);
    }

    public function create(Request $request): Response
    {
        return Response::json(['name' => $request->input('name')], 201);
    }
}

final class RoutingKernelTest extends TestCase
{
    private function kernel(?array $global = []): Kernel
    {
        $container = new Container();
        $container->bind(HomeController::class, HomeController::class);
        $container->bind(WidgetApiController::class, WidgetApiController::class);

        $router = new Router();
        $router->get('/', [HomeController::class, 'index']);
        $router->get('/widgets/{id:int}', [WidgetApiController::class, 'show']);
        $router->post('/widgets', [WidgetApiController::class, 'create'], [new ShortCircuitMiddleware()]);
        $router->group('/api', [], function (Router $r): void {
            $r->get('/ping', [HomeController::class, 'index']);
        });

        return new Kernel($container, $router, $global);
    }

    public function testRouteDispatchesToController(): void
    {
        $response = $this->kernel()->handle(new Request('GET', '/'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('<h1>home</h1>', $response->body);
    }

    public function testGlobalMiddlewareWrapsEveryResponse(): void
    {
        $response = $this->kernel([new GlobalHeaderMiddleware()])->handle(new Request('GET', '/'));

        $this->assertSame('on', $response->headers['X-Global'] ?? null);
    }

    public function testRouteParamsAreCastByType(): void
    {
        $response = $this->kernel()->handle(new Request('GET', '/widgets/42'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('42', $response->body);
    }

    public function testUnknownPathReturns404(): void
    {
        $this->assertSame(404, $this->kernel()->handle(new Request('GET', '/missing'))->status);
    }

    public function testKnownPathWithWrongMethodReturns405(): void
    {
        $this->assertSame(405, $this->kernel()->handle(new Request('DELETE', '/'))->status);
    }

    public function testRouteMiddlewareCanShortCircuit(): void
    {
        $response = $this->kernel()->handle(new Request('POST', '/widgets', body: ['name' => 'desk']));

        $this->assertSame(403, $response->status);
        $this->assertSame('blocked', $response->body);
    }

    public function testGroupPrefixAccumulates(): void
    {
        $response = $this->kernel()->handle(new Request('GET', '/api/ping'));

        $this->assertSame(200, $response->status);
    }

    public function testRequestBodyIsExposedThroughInput(): void
    {
        // Bypass the short-circuiting POST route to prove input() plumbing.
        $container = new Container();
        $container->bind(WidgetApiController::class, WidgetApiController::class);
        $router = new Router();
        $router->post('/free', [WidgetApiController::class, 'create']);
        $response = (new Kernel($container, $router))->handle(
            new Request('POST', '/free', body: ['name' => 'chair'])
        );

        $this->assertSame(201, $response->status);
        $this->assertStringContainsString('chair', $response->body);
    }

    public function testRequestHeadersAreCaseInsensitive(): void
    {
        $request = new Request('GET', '/', headers: ['X-CSRF-Token' => 'abc']);

        $this->assertSame('abc', $request->header('x-csrf-token'));
        $this->assertSame('abc', $request->header('X-CSRF-TOKEN'));
    }

    public function testResponseJsonHelpersProduceCorrectShape(): void
    {
        $json = Response::json(['ok' => true], 201);
        $text = Response::text('plain', 418);
        $redirect = Response::redirect('/elsewhere');

        $this->assertSame(201, $json->status);
        $this->assertSame('application/json', $json->headers['Content-Type'] ?? null);
        $this->assertSame(418, $text->status);
        $this->assertSame(302, $redirect->status);
        $this->assertSame('/elsewhere', $redirect->headers['Location'] ?? null);
    }
}
