<?php

declare(strict_types=1);

namespace Tests\Framework;

use LombokClarion\View\Safe;
use LombokClarion\View\Theme;
use LombokClarion\View\ViewEngine;
use LombokClarion\View\Exceptions\ViewException;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $templates;
    private string $cache;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/lombokclarion-view-' . bin2hex(random_bytes(4));
        $this->templates = $base . '/templates';
        $this->cache = $base . '/cache';
        mkdir($this->templates, 0777, true);
        mkdir($this->cache, 0777, true);

        file_put_contents($this->templates . '/greet.lc.php', 'Hello {{ $name }}!');
        file_put_contents($this->templates . '/raw.lc.php', '<b>{!! $html !!}</b>');
        file_put_contents($this->templates . '/loop.lc.php', '@foreach($items as $item){{ $item }}:@endforeach');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cache . '/*.php') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->templates . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cache);
        @rmdir($this->templates);
        @rmdir(dirname($this->cache));
    }

    private function engine(): ViewEngine
    {
        return new ViewEngine($this->templates, $this->cache);
    }

    public function testRendersAndAutoEscapes(): void
    {
        $html = $this->engine()->render('greet', ['name' => '<script>x</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testNullDataRendersEmptyString(): void
    {
        $html = $this->engine()->render('greet', ['name' => null]);

        $this->assertSame('Hello !', $html);
    }

    public function testRawOutputRequiresSafeMarker(): void
    {
        $engine = $this->engine();

        $html = $engine->render('raw', ['html' => Safe::mark('<i>ok</i>')]);
        $this->assertStringContainsString('<i>ok</i>', $html);
    }

    public function testEscapeIsExplicitAndStatic(): void
    {
        $this->assertSame('&lt;b&gt;', ViewEngine::escape('<b>'));
        $this->assertSame('plain', ViewEngine::escape('plain'));
    }

    public function testMissingTemplateThrowsViewException(): void
    {
        $this->expectException(ViewException::class);
        $this->engine()->render('does-not-exist');
    }

    public function testCompiledTemplatesAreCached(): void
    {
        $engine = $this->engine();
        $engine->render('greet', ['name' => 'a']);

        $compiled = glob($this->cache . '/*.php') ?: [];
        $this->assertNotEmpty($compiled);

        // Second render must hit the compiled path and stay correct.
        $this->assertStringContainsString('Hello b!', $engine->render('greet', ['name' => 'b']));
    }

    public function testLoopCompilesAndRuns(): void
    {
        $html = $this->engine()->render('loop', ['items' => ['a', 'b', 'c']]);

        $this->assertSame('a:b:c:', $html);
    }

    public function testThemeValidatesPresets(): void
    {
        $theme = new Theme('quiet-editorial');
        $this->assertSame('quiet-editorial', $theme->style);

        $this->expectException(ViewException::class);
        new Theme('not-a-real-theme');
    }
}
