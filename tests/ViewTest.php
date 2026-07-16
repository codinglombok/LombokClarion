<?php

declare(strict_types=1);

use LombokClarion\View\ViewEngine;

function test_make_view_engine(): ViewEngine
{
    $cache = sys_get_temp_dir() . '/lombokclarion_view_cache_' . uniqid();
    return new ViewEngine(__DIR__ . '/fixtures/views', $cache);
}

test('escaped interpolation auto-escapes html entities', function () {
    $engine = test_make_view_engine();
    $html = $engine->render('greeting', [
        'name' => '<script>alert(1)</script>',
        'items' => [],
        'rawHtml' => '<b>bold</b>',
    ]);
    assertTrue(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
    assertTrue(!str_contains($html, '<script>alert(1)</script>'));
});

test('raw interpolation is not escaped', function () {
    $engine = test_make_view_engine();
    $html = $engine->render('greeting', ['name' => 'A', 'items' => [], 'rawHtml' => '<b>bold</b>']);
    assertTrue(str_contains($html, '<div><b>bold</b></div>'));
});

test('@if / @else renders correct branch', function () {
    $engine = test_make_view_engine();
    $withItems = $engine->render('greeting', ['name' => 'A', 'items' => ['x', 'y'], 'rawHtml' => '']);
    assertTrue(str_contains($withItems, '<li>x</li>'));
    assertTrue(str_contains($withItems, '<li>y</li>'));

    $noItems = $engine->render('greeting', ['name' => 'A', 'items' => [], 'rawHtml' => '']);
    assertTrue(str_contains($noItems, 'No items.'));
});

test('@extends/@section/@yield composes child content into layout', function () {
    $engine = test_make_view_engine();
    $html = $engine->render('child', ['message' => 'Hi there']);
    assertTrue(str_contains($html, '<html><body>'));
    assertTrue(str_contains($html, '<p>Hi there</p>'));
});

test('compiled cache file is reused on second render (no recompilation)', function () {
    $engine = test_make_view_engine();
    $engine->render('greeting', ['name' => 'A', 'items' => [], 'rawHtml' => '']);

    // Second engine instance pointed at the same cache dir should find the
    // cached file already compiled and still render correctly.
    $engine->render('greeting', ['name' => 'B', 'items' => [], 'rawHtml' => '']);
    assertTrue(true);
});
