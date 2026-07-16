<?php

declare(strict_types=1);

use LombokClarion\Container\Container;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Routing\Kernel;
use LombokClarion\Routing\Router;
use LombokClarion\Security\SecurityHeaders;
use LombokClarion\View\AssetManifest;
use LombokClarion\View\AssetPublisher;
use LombokClarion\View\Exceptions\ViewException;
use LombokClarion\View\StaticAssetsMiddleware;
use LombokClarion\View\Theme;

function test_assets_tmpdir(): string
{
    $dir = sys_get_temp_dir() . '/lc_assets_' . uniqid();
    mkdir($dir, 0775, true);
    return $dir;
}

test('AssetPublisher writes content-hashed file and manifest', function () {
    $src = test_assets_tmpdir();
    $out = test_assets_tmpdir();
    file_put_contents("$src/app.css", 'body{color:red}');

    $manifest = (new AssetPublisher())->publish(
        ['app.css' => "$src/app.css"],
        $out,
        "$out/manifest.php"
    );

    assertTrue((bool) preg_match('/^app\.[0-9a-f]{12}\.css$/', $manifest['app.css']));
    assertTrue(is_file("$out/{$manifest['app.css']}"));
    $loaded = require "$out/manifest.php";
    assertSame($manifest, $loaded);
});

test('AssetPublisher: changed content changes the hash and prunes the stale file', function () {
    $src = test_assets_tmpdir();
    $out = test_assets_tmpdir();
    file_put_contents("$src/app.css", 'v1');
    $m1 = (new AssetPublisher())->publish(['app.css' => "$src/app.css"], $out, "$out/manifest.php");

    file_put_contents("$src/app.css", 'v2');
    $m2 = (new AssetPublisher())->publish(['app.css' => "$src/app.css"], $out, "$out/manifest.php");

    assertTrue($m1['app.css'] !== $m2['app.css'], 'hash must change with content');
    assertTrue(!is_file("$out/{$m1['app.css']}"), 'stale hashed file must be pruned');
    assertTrue(is_file("$out/{$m2['app.css']}"));
});

test('AssetManifest resolves hashed URL and rejects unknown assets', function () {
    $manifest = new AssetManifest(['app.css' => 'app.abcabcabcabc.css']);
    assertSame('/assets/app.abcabcabcabc.css', $manifest->url('app.css'));
    assertThrows(ViewException::class, fn () => $manifest->url('missing.css'));
});

test('AssetManifest falls back to identity mapping when no manifest exists (dev mode)', function () {
    $manifest = AssetManifest::fromFile('/nonexistent/manifest.php');
    assertSame('/assets/app.css', $manifest->url('app.css'));
});

test('Theme accepts the 4 starter-kit presets and upstream presets', function () {
    foreach ([...Theme::STARTER_KIT_PRESETS, ...Theme::UPSTREAM_PRESETS] as $preset) {
        assertSame($preset, (new Theme($preset))->style);
    }
});

test('Theme rejects an unknown style at construction', function () {
    assertThrows(ViewException::class, fn () => new Theme('hotdog-stand'));
});

test('vendored LombokCSS actually defines the 3 upstream starter-kit themes; extension defines the 4th', function () {
    $root = dirname(__DIR__);
    $css = file_get_contents($root . '/resources/lombokcss/lombok.min.css');
    foreach (['resonant-stark', 'neo-brutalism', 'glassmorphism'] as $preset) {
        // Minifiers strip quotes from attribute selectors, so accept both forms.
        $found = str_contains($css, "data-style=\"$preset\"") || str_contains($css, "data-style=$preset");
        assertTrue($found, "lombok.min.css must define $preset");
    }
    $ext = file_get_contents($root . '/resources/lombokcss/quiet-editorial.css');
    assertTrue(str_contains($ext, 'data-style="quiet-editorial"'));
});

test('StaticAssetsMiddleware serves an existing asset with immutable cache header', function () {
    $dir = test_assets_tmpdir();
    file_put_contents("$dir/x.css", 'a{}');
    $mw = new StaticAssetsMiddleware($dir);

    $response = $mw->handle(new Request('GET', '/assets/x.css'), fn ($r) => Response::text('miss', 404));
    assertSame(200, $response->status);
    assertSame('public, max-age=31536000, immutable', $response->headers['Cache-Control']);
    assertSame('text/css; charset=utf-8', $response->headers['Content-Type']);
});

test('StaticAssetsMiddleware blocks path traversal', function () {
    $dir = test_assets_tmpdir();
    $secret = test_assets_tmpdir();
    file_put_contents("$secret/secret.txt", 'nope');
    $mw = new StaticAssetsMiddleware($dir);

    $response = $mw->handle(
        new Request('GET', '/assets/../' . basename($secret) . '/secret.txt'),
        fn ($r) => Response::text('miss', 404)
    );
    assertSame(404, $response->status);
    assertTrue(!str_contains($response->body, 'nope'));
});

test('StaticAssetsMiddleware passes non-asset requests through to next', function () {
    $mw = new StaticAssetsMiddleware(test_assets_tmpdir());
    $response = $mw->handle(new Request('GET', '/widgets'), fn ($r) => Response::text('routed'));
    assertSame('routed', $response->body);
});

test('global middleware runs even for unmatched (404) paths', function () {
    $kernel = new Kernel(new Container(), new Router(), globalMiddleware: [SecurityHeaders::class]);
    $response = $kernel->handle(new Request('GET', '/definitely-not-a-route'));
    assertSame(404, $response->status);
    assertSame('DENY', $response->headers['X-Frame-Options'], 'security headers must apply to 404s too');
});

test('vendored LombokCharts UMD build defines the global and core marks', function () {
    $js = file_get_contents(dirname(__DIR__) . '/resources/lombokcharts/lombok-charts.umd.min.js');
    assertTrue(str_contains($js, 'LombokCharts'), 'UMD global must be present');
    foreach (['"bar"', '"line"', '"arc"'] as $mark) {
        assertTrue(str_contains($js, $mark), "mark $mark must be registered in the build");
    }
});

test('dashboard chart JSON is script-breakout safe via JSON_HEX_TAG', function () {
    $payload = [['label' => 'x</script><script>alert(1)</script>', 'value' => 1]];
    $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    assertTrue(!str_contains($json, '</script>'), 'closing tag must be hex-escaped');
    assertTrue(str_contains($json, '\u003C'), 'angle brackets must be \u003C-escaped');
});
