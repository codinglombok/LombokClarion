<?php

declare(strict_types=1);

use LombokClarion\Container\CompiledContainer;
use LombokClarion\Http\Request;
use LombokClarion\Routing\CompiledRouteMatcher;
use LombokClarion\Routing\Kernel;
use LombokClarion\Security\PasswordHasher;
use LombokClarion\Security\SecurityHeaders;

/**
 * Findings #40–#43 (docs/AUDIT-TRAIL.md): all four were invisible to a
 * 235-test suite because nothing ever booted the app THE WAY PRODUCTION
 * BOOTS IT — from the compiled artifacts, through the real bootstrap
 * files, with a hand-built Request. These tests do exactly that, so the
 * whole class of "artifact produced, verified in isolation, consumed by
 * nothing" cannot come back silently.
 */

/** Boot the real app the way public/index.php does, on either path. */
function test_bootRealApp(bool $compiled): array
{
    $root = dirname(__DIR__);

    if ($compiled) {
        $container = CompiledContainer::fromFile($root . '/storage/services.compiled.php');
        foreach ((require $root . '/bootstrap/externals.php')() as $id => $instance) {
            $container->instance($id, $instance);
        }
    } else {
        $container = (require $root . '/bootstrap/services.php')();
    }

    $router = (require $root . '/bootstrap/routes.php')($container);
    $matcher = $compiled
        ? CompiledRouteMatcher::fromFile($root . '/storage/routes.compiled.php', $router)
        : null;

    $kernel = new Kernel($container, $router, [SecurityHeaders::class], compiledMatcher: $matcher);

    return [$container, $kernel];
}

function test_compiledArtifactsPresent(): bool
{
    $root = dirname(__DIR__);

    return is_file($root . '/storage/services.compiled.php')
        && is_file($root . '/storage/routes.compiled.php');
}

test('#43: a hand-built Request normalizes header casing — Authorization is not silently lost', function () {
    $request = new Request('GET', '/x', headers: [
        'Authorization' => 'Bearer abc',
        'X-CUSTOM-Thing' => 'v',
    ]);

    // Any casing on lookup, any casing at construction (RFC 9110 §5.1).
    assertSame('Bearer abc', $request->header('Authorization'));
    assertSame('Bearer abc', $request->header('authorization'));
    assertSame('v', $request->header('x-custom-thing'));

    // The stored array itself is normalized — raw readers see one shape.
    assertSame(['authorization', 'x-custom-thing'], array_keys($request->headers));
});

test('#40/#41: dev and compiled boots answer the auth flow IDENTICALLY (parity, end-to-end)', function () {
    if (!test_compiledArtifactsPresent()) {
        // The gate runs `optimize` before the suite in CI order; locally,
        // run it once. Skipping silently would defeat the test's reason to
        // exist, so absence is a failure with the fix in the message.
        assertTrue(false, 'storage/*.compiled.php missing — run `php bin/lombokclarion migrate && php bin/lombokclarion optimize` first');
    }

    $results = [];

    foreach ([false, true] as $compiled) {
        [$container, $kernel] = test_bootRealApp($compiled);

        $pdo = $container->get(PDO::class);
        $pdo->exec("DELETE FROM users WHERE email='parity@test.local'");
        $pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (?,?,?)')->execute([
            'u-parity-1',
            'parity@test.local',
            $container->get(PasswordHasher::class)->hash('secret123'),
        ]);

        $auth = $container->get(LombokClarion\Auth\AuthManager::class);
        $token = $auth->login($auth->attempt('parity@test.local', 'secret123'));

        // Mixed-case header on purpose: #43 and #41 in one request.
        $ok = $kernel->handle(new Request('GET', '/me', headers: ['Authorization' => "Bearer $token"]));
        $no = $kernel->handle(new Request('GET', '/me'));

        $results[$compiled ? 'compiled' : 'dev'] = [$ok->status, $ok->body, $no->status];

        $pdo->exec("DELETE FROM users WHERE email='parity@test.local'");
    }

    // Each path is right on its own...
    assertSame(200, $results['dev'][0]);
    assertSame('{"id":"u-parity-1"}', $results['dev'][1]);
    assertSame(401, $results['dev'][2]);

    // ...and the two paths are byte-identical. Parity IS the invariant:
    // before #40, the compiled path could rot arbitrarily far because
    // nothing compared it against the path everyone actually ran.
    assertSame($results['dev'], $results['compiled']);
});

test('#41: the compiled graph resolves every route middleware and controller', function () {
    if (!test_compiledArtifactsPresent()) {
        assertTrue(false, 'storage/*.compiled.php missing — run `php bin/lombokclarion migrate && php bin/lombokclarion optimize` first');
    }

    [$container, ] = test_bootRealApp(true);
    $root = dirname(__DIR__);
    $router = (require $root . '/bootstrap/routes.php')($container);

    // Walk the real route table: every class-string middleware and every
    // controller must resolve from the COMPILED container. This is the
    // structural version of the AuthController hole — a Stage-N route
    // addition that forgets extraRootIds now fails here, by name, instead
    // of 500ing in production only.
    foreach ($router->routes() as $route) {
        foreach ($route->middleware as $entry) {
            if (is_string($entry)) {
                assertTrue(
                    $container->get($entry) instanceof LombokClarion\Http\Middleware,
                    "compiled graph missing middleware $entry for {$route->method} {$route->path}"
                );
            }
        }

        $controller = $route->handler[0];
        assertTrue(
            is_object($container->get($controller)),
            "compiled graph missing controller $controller for {$route->method} {$route->path}"
        );
    }
});
