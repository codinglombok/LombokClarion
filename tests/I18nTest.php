<?php

declare(strict_types=1);

use LombokClarion\Http\Request;
use LombokClarion\Http\RequestContext;
use LombokClarion\Http\Response;
use LombokClarion\I18n\DetectLocale;
use LombokClarion\I18n\Translator;

function test_make_translator(): Translator
{
    $t = new Translator('id', 'en');
    $t->addCatalog('en', require dirname(__DIR__) . '/resources/lang/en.php');
    $t->addCatalog('id', require dirname(__DIR__) . '/resources/lang/id.php');
    return $t;
}

test('trans returns localized message with :param interpolation', function () {
    $t = test_make_translator();
    assertSame('Selamat datang, Ana!', $t->trans('welcome', ['name' => 'Ana']));
    $t->setLocale('en');
    assertSame('Welcome, Ana!', $t->trans('welcome', ['name' => 'Ana']));
});

test('choice pluralizes on count with :count available', function () {
    $t = test_make_translator();
    assertSame('satu widget', $t->choice('widgets.count', 1));
    assertSame('5 widget', $t->choice('widgets.count', 5));
    $t->setLocale('en');
    assertSame('5 widgets', $t->choice('widgets.count', 5));
});

test('missing key falls back, then returns key and is recorded (not silent)', function () {
    $t = new Translator('id', 'en');
    $t->addCatalog('en', ['only.en' => 'English only']);
    assertSame('English only', $t->trans('only.en')); // fallback hit
    assertSame('ghost.key', $t->trans('ghost.key'));  // nowhere -> key itself
    assertSame(['id.ghost.key'], $t->missingKeys());
    assertThrows(RuntimeException::class, fn () => $t->assertNoMissingKeys());
});

test('DetectLocale honors ?lang= within the allow-list and stores in context', function () {
    $t = test_make_translator();
    $ctx = new RequestContext();
    $mw = new DetectLocale($t, $ctx, ['en', 'id']);
    $mw->handle(new Request('GET', '/', query: ['lang' => 'en']), fn ($r) => Response::text('ok'));
    assertSame('en', $t->locale());
    assertSame('en', $ctx->get('locale'));
});

test('DetectLocale negotiates Accept-Language incl. primary-tag match, rejects unsupported', function () {
    $t = test_make_translator(); // default id
    $mw = new DetectLocale($t, new RequestContext(), ['en', 'id']);
    $mw->handle(new Request('GET', '/', headers: ['accept-language' => 'fr-FR, en-US;q=0.8']),
        fn ($r) => Response::text('ok'));
    assertSame('en', $t->locale()); // fr rejected, en-US matches primary "en"

    $t2 = test_make_translator();
    $mw2 = new DetectLocale($t2, new RequestContext(), ['en', 'id']);
    $mw2->handle(new Request('GET', '/', query: ['lang' => 'xx']), fn ($r) => Response::text('ok'));
    assertSame('id', $t2->locale()); // unsupported ?lang ignored, default kept
});

test('every shipped locale catalog is parity-complete with en (24 locales)', function () {
    $dir = dirname(__DIR__) . '/resources/lang';
    $en = require "$dir/en.php";
    $files = glob("$dir/*.php");
    assertTrue(count($files) >= 24, 'expected 24+ locale catalogs, got ' . count($files));
    foreach ($files as $file) {
        $cat = require $file;
        $missing = array_values(array_diff(array_keys($en), array_keys($cat)));
        assertSame([], $missing, basename($file) . ' is missing keys: ' . implode(',', $missing));
    }
});

test('validation package ships parity-complete catalogs and Lang::LOCALES matches disk', function () {
    $dir = dirname(__DIR__) . '/packages/validation/resources/lang';
    $onDisk = array_map(fn ($f) => basename($f, '.php'), glob("$dir/*.php"));
    sort($onDisk);

    // constant <-> disk, both directions — no orphan file, no phantom locale
    assertSame(LombokClarion\Validation\Lang::LOCALES, $onDisk);
    assertSame(24, count($onDisk));

    $en = LombokClarion\Validation\Lang::catalog('en');
    assertTrue(count($en) >= 11, 'expected 11+ validation.* keys in en, got ' . count($en));
    foreach (LombokClarion\Validation\Lang::LOCALES as $locale) {
        $cat = LombokClarion\Validation\Lang::catalog($locale);
        $missing = array_values(array_diff(array_keys($en), array_keys($cat)));
        assertSame([], $missing, "$locale.php is missing keys: " . implode(',', $missing));
        foreach ($cat as $key => $msg) {
            assertTrue(str_starts_with($key, 'validation.'), "$locale.php carries non-validation key \"$key\"");
        }
    }

    // unknown locale is a hard error, not a silent empty catalog
    try {
        LombokClarion\Validation\Lang::catalog('xx');
        assertTrue(false, 'expected RuntimeException for unknown locale');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'xx'));
    }
});

test('app catalogs no longer carry validation.* — the package owns them', function () {
    $dir = dirname(__DIR__) . '/resources/lang';
    foreach (glob("$dir/*.php") as $file) {
        foreach (array_keys(require $file) as $key) {
            assertTrue(!str_starts_with($key, 'validation.'),
                basename($file) . " still carries \"$key\" — validation.* ships with lombokclarion/validation");
        }
    }
});

test('a sample of world locales translate and pluralize correctly', function () {
    $dir = dirname(__DIR__) . '/resources/lang';
    foreach (['ja' => 'ようこそ、Anaさん！', 'ar' => 'مرحبًا يا Ana!', 'es' => '¡Bienvenido, Ana!'] as $loc => $expected) {
        $t = new Translator($loc, 'en');
        $t->addCatalog('en', require "$dir/en.php");
        $t->addCatalog($loc, require "$dir/$loc.php");
        assertSame($expected, $t->trans('welcome', ['name' => 'Ana']));
        assertTrue(str_contains($t->choice('widgets.count', 5), '5'));
    }
});
