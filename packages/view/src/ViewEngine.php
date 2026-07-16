<?php

declare(strict_types=1);

namespace LombokClarion\View;

use LombokClarion\View\Exceptions\ViewException;

/**
 * Templates compile once and are cached to disk (§4 build order item 8:
 * "compiled/cached ahead of time"). Re-compilation only happens when the
 * source template is newer than its cached PHP, so production requests
 * never touch the compiler.
 */
final class ViewEngine
{
    private array $sections = [];

    /** @var list<string> */
    private array $sectionStack = [];

    private ?string $pendingExtend = null;

    public function __construct(
        private readonly string $templatesPath,
        private readonly string $cachePath,
        private readonly ViewCompiler $compiler = new ViewCompiler(),
    ) {
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        $this->sections = [];
        $this->sectionStack = [];
        $this->pendingExtend = null;

        return $this->renderTemplate($name, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderTemplate(string $name, array $data): string
    {
        $compiledPath = $this->ensureCompiled($name);
        $contents = $this->renderCompiledFile($compiledPath, $data);

        if ($this->pendingExtend !== null) {
            $layout = $this->pendingExtend;
            $this->pendingExtend = null;
            return $this->renderTemplate($layout, $data);
        }

        return $contents;
    }

    public function extend(string $layout): void
    {
        $this->pendingExtend = $layout;
    }

    public function startSection(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function endSection(): void
    {
        $name = array_pop($this->sectionStack);
        if ($name === null) {
            throw new ViewException('@endsection with no matching @section.');
        }
        $this->sections[$name] = ob_get_clean();
    }

    public function yieldContent(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderCompiledFile(string $compiledPath, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include $compiledPath;
        return ob_get_clean();
    }

    private function ensureCompiled(string $name): string
    {
        $sourcePath = $this->templatesPath . '/' . str_replace('.', '/', $name) . '.lc.php';
        if (!is_file($sourcePath)) {
            throw new ViewException("View \"$name\" not found at $sourcePath.");
        }

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0775, true);
        }

        $cachedPath = $this->cachePath . '/' . md5($name) . '.php';

        if (!is_file($cachedPath) || filemtime($sourcePath) > filemtime($cachedPath)) {
            $compiled = $this->compiler->compile(file_get_contents($sourcePath));
            $tmp = $cachedPath . '.tmp';
            file_put_contents($tmp, $compiled);
            rename($tmp, $cachedPath);
        }

        return $cachedPath;
    }
}
