<?php

declare(strict_types=1);

use LombokClarion\Container\Container;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Routing\CompiledRouteMatcher;
use LombokClarion\Routing\Kernel;
use LombokClarion\Routing\Route;
use LombokClarion\Routing\RouteCompiler;
use LombokClarion\Routing\Router;

/** Controller used by the typed-param dispatch tests. */
final class Test_TypedParamController
{
    public function show(Request $request): Response
    {
        $id = $request->attribute('id');

        // The assertion lives here, at the exact point the spec promises the
        // cast has already happened: BEFORE the controller runs.
        return Response::json(['id' => $id, 'type' => get_debug_type($id)]);
    }
}

// --- typed route params --------------------------------------------------

test('{id:int} narrows the match: non-digits are 404, not a controller call', function () {
    $router = new Router();
    $router->get('/widgets/{id:int}', [Test_TypedParamController::class, 'show']);
    $kernel = new Kernel(new Container(), $router);

    $ok = $kernel->handle(new Request('GET', '/widgets/42'));
    assertSame(200, $ok->status);

    // "abc" never reaches the controller — there is no "matched but invalid
    // param" state. 404 (no route), not 422 (route with bad input).
    $bad = $kernel->handle(new Request('GET', '/widgets/abc'));
    assertSame(404, $bad->status);

    // Mixed digits+letters must not match either ("\d+" is anchored inside
    // the full-path regex, but 12abc as a segment is not all-digits).
    assertSame(404, $kernel->handle(new Request('GET', '/widgets/12abc'))->status);
});

test('{id:int} casts before the controller: the attribute is a PHP int', function () {
    $router = new Router();
    $router->get('/widgets/{id:int}', [Test_TypedParamController::class, 'show']);
    $kernel = new Kernel(new Container(), $router);

    $response = $kernel->handle(new Request('GET', '/widgets/42'));
    $body = json_decode($response->body, true);

    assertSame(42, $body['id']);
    assertSame('int', $body['type']);
});

test('untyped and {x:string} params stay strings — the default is unchanged', function () {
    $router = new Router();
    $r1 = new Route('GET', '/a/{slug}', [Test_TypedParamController::class, 'show']);
    $r2 = new Route('GET', '/b/{slug:string}', [Test_TypedParamController::class, 'show']);

    assertSame(['slug' => 'hello-world'], $r1->match('/a/hello-world'));
    assertSame(['slug' => '42'], $r2->match('/b/42'));
});

test('an unknown param type is a loud compile error, not a match-everything', function () {
    $route = new Route('GET', '/x/{id:uuid}', [Test_TypedParamController::class, 'show']);

    // Lazy compile: the error surfaces on first use (match/compiled), with the
    // path and the known-type list in the message.
    assertThrows(InvalidArgumentException::class, fn () => $route->match('/x/1'));
});

// --- route compilation ---------------------------------------------------

/** A router mixing static and typed-dynamic routes, plus its compiled matcher. */
function test_compiledPair(): array
{
    $router = new Router();
    $router->get('/widgets', [Test_TypedParamController::class, 'show']);
    $router->get('/widgets/{id:int}', [Test_TypedParamController::class, 'show']);
    $router->post('/widgets', [Test_TypedParamController::class, 'show']);
    $router->get('/tags/{slug}', [Test_TypedParamController::class, 'show']);

    $file = sys_get_temp_dir() . '/lc_routes_' . uniqid() . '.php';
    (new RouteCompiler())->compileToFile($router, $file);
    $matcher = CompiledRouteMatcher::fromFile($file, $router);
    unlink($file);

    return [$router, $matcher];
}

test('compiled matcher agrees with live matching on hits, misses, params, and casts', function () {
    [$router, $matcher] = test_compiledPair();

    // Static hit: index resolves to the same Route object live matching finds.
    [$index, $params] = $matcher->match('GET', '/widgets');
    assertSame($router->routes()[$index], $router->match('GET', '/widgets')[0]);
    assertSame([], $params);

    // Dynamic hit with the int cast applied — identical to the live path.
    [, $params] = $matcher->match('GET', '/widgets/42');
    assertSame(['id' => 42], $params);
    assertSame(['id' => 42], $router->match('GET', '/widgets/42')[1]);

    // Type narrowing survives compilation.
    assertSame(null, $matcher->match('GET', '/widgets/abc'));

    // Method-aware; pathExists is method-blind (the 405 distinction).
    assertSame(null, $matcher->match('DELETE', '/widgets'));
    assertTrue($matcher->pathExists('/widgets'));
    assertTrue(!$matcher->pathExists('/nope'));
});

test('a stale compiled index is refused at construction, naming the fix', function () {
    $router = new Router();
    $router->get('/widgets', [Test_TypedParamController::class, 'show']);

    $file = sys_get_temp_dir() . '/lc_routes_' . uniqid() . '.php';
    (new RouteCompiler())->compileToFile($router, $file);

    // The table changes after compilation — one added route.
    $router->get('/gadgets', [Test_TypedParamController::class, 'show']);

    try {
        CompiledRouteMatcher::fromFile($file, $router);
        assertTrue(false, 'expected RuntimeException for stale index');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'optimize'));
    } finally {
        unlink($file);
    }
});

test('reordering routes invalidates the index even though the route set is identical', function () {
    // Precedence is declaration order; an index built against the old order
    // would dispatch to the wrong route where patterns overlap.
    $a = new Router();
    $a->get('/x/{id:int}', [Test_TypedParamController::class, 'show']);
    $a->get('/x/{slug}', [Test_TypedParamController::class, 'show']);

    $b = new Router();
    $b->get('/x/{slug}', [Test_TypedParamController::class, 'show']);
    $b->get('/x/{id:int}', [Test_TypedParamController::class, 'show']);

    assertTrue(RouteCompiler::fingerprint($a) !== RouteCompiler::fingerprint($b));
});

test('Kernel dispatches through the compiled matcher end-to-end', function () {
    [$router, $matcher] = test_compiledPair();
    $kernel = new Kernel(new Container(), $router, compiledMatcher: $matcher);

    $response = $kernel->handle(new Request('GET', '/widgets/7'));
    $body = json_decode($response->body, true);
    assertSame(7, $body['id']);
    assertSame('int', $body['type']);

    assertSame(404, $kernel->handle(new Request('GET', '/widgets/x'))->status);
    assertSame(405, $kernel->handle(new Request('DELETE', '/widgets'))->status);
});

// --- the Stage 11 budget -------------------------------------------------

test('500-route compiled match stays under the 0.5ms budget (worst case)', function () {
    $router = new Router();

    // 250 static + 250 typed-dynamic routes; the route asked for is the LAST
    // dynamic one registered, i.e. the worst case for the regex scan. A
    // median-case benchmark would flatter the number; the budget is only
    // meaningful against the slowest path.
    for ($i = 0; $i < 250; $i++) {
        $router->get("/static/page$i", [Test_TypedParamController::class, 'show']);
        $router->get("/resource$i/{id:int}", [Test_TypedParamController::class, 'show']);
    }

    $file = sys_get_temp_dir() . '/lc_bench_' . uniqid() . '.php';
    (new RouteCompiler())->compileToFile($router, $file);
    $matcher = CompiledRouteMatcher::fromFile($file, $router);
    unlink($file);

    $worstPath = '/resource249/123';

    // Warm up (opcache/JIT-free CLI still benefits from a primed data path),
    // then time many iterations and take the mean — a single-iteration timing
    // at microsecond scale is noise, not measurement.
    $matcher->match('GET', $worstPath);

    $iterations = 200;
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $result = $matcher->match('GET', $worstPath);
    }
    $meanMs = (hrtime(true) - $start) / $iterations / 1_000_000;

    assertSame(['id' => 123], $result[1]);
    assertTrue(
        $meanMs < 0.5,
        sprintf('budget blown: %.4fms mean per worst-case match (budget 0.5ms)', $meanMs)
    );

    // And the static half of the same 500-route table: one hash lookup.
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $matcher->match('GET', '/static/page249');
    }
    $staticMeanMs = (hrtime(true) - $start) / $iterations / 1_000_000;
    assertTrue($staticMeanMs < 0.5, sprintf('static lookup: %.4fms', $staticMeanMs));
});
