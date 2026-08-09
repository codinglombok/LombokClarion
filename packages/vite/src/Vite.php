<?php

declare(strict_types=1);

namespace LombokClarion\Vite;

use LombokClarion\View\Safe;

/**
 * Vite integration for LombokClarion views.
 *
 * In development: proxies to the Vite dev server (hot module replacement).
 * In production: reads the Vite manifest.json and emits hashed asset URLs.
 *
 * Usage in templates:
 *   {!! $vite->assets('resources/js/app.js', 'resources/css/app.css') !!}
 */
final class Vite
{
    /** @var array<string, array{file: string, css?: list<string>}>|null */
    private ?array $manifest = null;

    /**
     * @param string      $manifestPath  path to Vite's manifest.json (production)
     * @param string      $buildDir      public subdirectory for built assets
     * @param string|null $devServerUrl  Vite dev server URL, null = production mode
     * @param string      $hotFilePath   path to hot file (signals dev mode)
     */
    public function __construct(
        private readonly string $manifestPath,
        private readonly string $buildDir = 'build',
        private readonly ?string $devServerUrl = null,
        private readonly string $hotFilePath = '',
    ) {
    }

    /**
     * Detect whether the Vite dev server is running.
     */
    public function isDev(): bool
    {
        if ($this->devServerUrl !== null) {
            return true;
        }

        if ($this->hotFilePath !== '' && is_file($this->hotFilePath)) {
            return true;
        }

        return false;
    }

    /**
     * Return the dev server URL from the hot file or constructor.
     */
    private function devUrl(): string
    {
        if ($this->devServerUrl !== null) {
            return rtrim($this->devServerUrl, '/');
        }

        if ($this->hotFilePath !== '' && is_file($this->hotFilePath)) {
            return rtrim(trim(file_get_contents($this->hotFilePath)), '/');
        }

        return 'http://localhost:5173';
    }

    /**
     * Generate HTML tags for the given entrypoints.
     *
     * @param string ...$entrypoints e.g. 'resources/js/app.js', 'resources/css/app.css'
     * @return Safe pre-escaped HTML tags
     */
    public function assets(string ...$entrypoints): Safe
    {
        if ($this->isDev()) {
            return $this->devTags($entrypoints);
        }

        return $this->productionTags($entrypoints);
    }

    /**
     * @param list<string> $entrypoints
     */
    private function devTags(array $entrypoints): Safe
    {
        $url = $this->devUrl();
        $html = '<script type="module" src="' . $url . '/@vite/client"></script>' . "\n";

        foreach ($entrypoints as $entry) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($ext === 'css') {
                $html .= '<link rel="stylesheet" href="' . $url . '/' . $entry . '">' . "\n";
            } else {
                $html .= '<script type="module" src="' . $url . '/' . $entry . '"></script>' . "\n";
            }
        }

        return Safe::mark($html);
    }

    /**
     * @param list<string> $entrypoints
     */
    private function productionTags(array $entrypoints): Safe
    {
        $manifest = $this->loadManifest();
        $html = '';

        foreach ($entrypoints as $entry) {
            if (!isset($manifest[$entry])) {
                throw new \RuntimeException("Vite entrypoint not found in manifest: {$entry}");
            }

            $chunk = $manifest[$entry];

            // CSS chunks
            foreach ($chunk['css'] ?? [] as $cssFile) {
                $html .= '<link rel="stylesheet" href="/' . $this->buildDir . '/' . $cssFile . '">' . "\n";
            }

            // JS entrypoint
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($ext !== 'css') {
                $html .= '<script type="module" src="/' . $this->buildDir . '/' . $chunk['file'] . '"></script>' . "\n";
            } else {
                $html .= '<link rel="stylesheet" href="/' . $this->buildDir . '/' . $chunk['file'] . '">' . "\n";
            }
        }

        return Safe::mark($html);
    }

    /**
     * @return array<string, array{file: string, css?: list<string>}>
     */
    private function loadManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (!is_file($this->manifestPath)) {
            throw new \RuntimeException(
                "Vite manifest not found: {$this->manifestPath}. Run `npx vite build` first."
            );
        }

        $json = file_get_contents($this->manifestPath);
        $this->manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->manifest;
    }

    /**
     * Generate a preload link for a specific asset.
     */
    public function preload(string $entrypoint): Safe
    {
        if ($this->isDev()) {
            return Safe::mark('');
        }

        $manifest = $this->loadManifest();
        if (!isset($manifest[$entrypoint])) {
            return Safe::mark('');
        }

        $file = $manifest[$entrypoint]['file'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $as = $ext === 'css' ? 'style' : 'script';

        return Safe::mark(
            '<link rel="modulepreload" href="/' . $this->buildDir . '/' . $file . '" as="' . $as . '">' . "\n"
        );
    }
}
