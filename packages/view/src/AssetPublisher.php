<?php

declare(strict_types=1);

namespace LombokClarion\View;

use LombokClarion\View\Exceptions\ViewException;

/**
 * Runs at `lombokclarion optimize` time (§8): copies each source asset to
 * the public assets directory under a content-hashed filename
 * (lombok.min.a1b2c3d4e5f6.css) and writes a manifest mapping logical
 * name => hashed filename. Views resolve through AssetManifest, so a
 * changed file automatically gets a new URL — which is what makes serving
 * with `Cache-Control: immutable` safe.
 *
 * Serving the file itself is the web server's job (nginx/CDN config sets
 * the immutable header on /assets/*); StaticAssetsMiddleware covers the
 * PHP-built-in-server dev case so behaviour matches locally.
 *
 * Stale previously-hashed copies of the same logical asset are pruned so
 * the assets directory doesn't accumulate one file per deploy forever.
 */
final class AssetPublisher
{
    /**
     * @param array<string, string> $assets logical name => absolute source path
     * @return array<string, string> logical name => hashed filename
     */
    public function publish(array $assets, string $publicAssetsDir, string $manifestPath): array
    {
        if (!is_dir($publicAssetsDir)) {
            mkdir($publicAssetsDir, 0775, true);
        }

        $manifest = [];

        foreach ($assets as $logicalName => $sourcePath) {
            if (!is_file($sourcePath)) {
                throw new ViewException("Asset source not found: $sourcePath");
            }

            $contents = file_get_contents($sourcePath);
            $hash = substr(hash('sha256', $contents), 0, 12);

            $dot = strrpos($logicalName, '.');
            if ($dot === false || $dot === 0) {
                throw new ViewException("Asset logical name \"$logicalName\" must include an extension.");
            }
            $base = substr($logicalName, 0, $dot);
            $ext = substr($logicalName, $dot + 1);

            $hashedName = "$base.$hash.$ext";

            // Prune stale hashed versions of this logical asset.
            foreach (glob($publicAssetsDir . "/$base.????????????.$ext") ?: [] as $stale) {
                if (basename($stale) !== $hashedName) {
                    unlink($stale);
                }
            }

            $target = $publicAssetsDir . '/' . $hashedName;
            $tmp = $target . '.tmp';
            file_put_contents($tmp, $contents);
            rename($tmp, $target);

            $manifest[$logicalName] = $hashedName;
        }

        $manifestSource = "<?php\n\ndeclare(strict_types=1);\n\n// GENERATED FILE — do not edit by hand."
            . "\n// Produced by LombokClarion\\View\\AssetPublisher at `lombokclarion optimize` time.\n\nreturn "
            . var_export($manifest, true) . ";\n";

        $tmp = $manifestPath . '.tmp';
        file_put_contents($tmp, $manifestSource);
        rename($tmp, $manifestPath);

        return $manifest;
    }
}
