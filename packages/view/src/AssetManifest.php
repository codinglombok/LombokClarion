<?php

declare(strict_types=1);

namespace LombokClarion\View;

use LombokClarion\View\Exceptions\ViewException;

/**
 * Injected into whatever composes view data (a controller or a small view
 * factory) — templates call {{ $assets->url('lombok.min.css') }} and get
 * the current content-hashed URL. Loaded from the manifest file written
 * by AssetPublisher; a plain PHP array require, opcache-friendly, no JSON
 * parsing per request (§5).
 *
 * In local dev before `optimize` has ever run, the manifest may not exist:
 * fromFile() then falls back to identity mapping (logical name served
 * as-is), so the dev loop doesn't require a build step.
 */
final class AssetManifest
{
    /**
     * @param array<string, string> $map logical name => hashed filename
     */
    public function __construct(
        private readonly array $map,
        private readonly string $baseUrl = '/assets',
    ) {
    }

    public static function fromFile(string $manifestPath, string $baseUrl = '/assets'): self
    {
        if (!is_file($manifestPath)) {
            return new self([], $baseUrl);
        }

        /** @var array<string, string> $map */
        $map = require $manifestPath;

        return new self($map, $baseUrl);
    }

    public function url(string $logicalName): string
    {
        if ($this->map === []) {
            // Dev fallback: no compiled manifest yet — serve unhashed.
            return rtrim($this->baseUrl, '/') . '/' . $logicalName;
        }

        if (!isset($this->map[$logicalName])) {
            throw new ViewException(
                "Asset \"$logicalName\" is not in the manifest. Add it to the optimize wiring " .
                "in bootstrap/console.php and re-run `lombokclarion optimize`."
            );
        }

        return rtrim($this->baseUrl, '/') . '/' . $this->map[$logicalName];
    }
}
