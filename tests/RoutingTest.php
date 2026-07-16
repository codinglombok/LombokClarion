<?php

declare(strict_types=1);

use LombokClarion\Container\Container;
use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Routing\Kernel;
use LombokClarion\Routing\Router;

final class Test_PingController
{
    public function show(Request $request): Response
    {
        return Response::json(['id' => $request->attribute('id')]);
    }
}

final class Test_UppercaseMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $response->withHeader('X-Upper', strtoupper($response->body));
    }
}

test('router matches path params and dispatches to controller', function () {
    $router = new Router();
    $router->get('/widgets/{id}', [Test_PingController::class, 'show']);

    $container = new Container();
    $kernel = new Kernel($container, $router);

    $response = $kernel->handle(new Request('GET', '/widgets/42'));
    assertSame(200, $response->status);
    assertSame('{"id":"42"}', $response->body);
});

test('unmatched path returns 404', function () {
    $router = new Router();
    $kernel = new Kernel(new Container(), $router);
    $response = $kernel->handle(new Request('GET', '/nope'));
    assertSame(404, $response->status);
});

test('matched path with wrong method returns 405', function () {
    $router = new Router();
    $router->get('/widgets/{id}', [Test_PingController::class, 'show']);
    $kernel = new Kernel(new Container(), $router);
    $response = $kernel->handle(new Request('DELETE', '/widgets/42'));
    assertSame(405, $response->status);
});

test('route middleware runs and can wrap the response', function () {
    $router = new Router();
    $router->get('/widgets/{id}', [Test_PingController::class, 'show'], [Test_UppercaseMiddleware::class]);
    $kernel = new Kernel(new Container(), $router);
    $response = $kernel->handle(new Request('GET', '/widgets/7'));
    assertSame('{"ID":"7"}', $response->headers['X-Upper']);
});

test('group applies prefix and middleware to nested routes', function () {
    $router = new Router();
    $router->group('/api', [Test_UppercaseMiddleware::class], function (Router $r) {
        $r->get('/widgets/{id}', [Test_PingController::class, 'show']);
    });
    $kernel = new Kernel(new Container(), $router);
    $response = $kernel->handle(new Request('GET', '/api/widgets/1'));
    assertSame(200, $response->status);
    assertSame('{"ID":"1"}', $response->headers['X-Upper']);
});

test('Response::json produces application/json content type', function () {
    $response = Response::json(['a' => 1]);
    assertSame('application/json', $response->headers['Content-Type']);
});
