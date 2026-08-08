<?php

declare(strict_types=1);

namespace LombokClarion\Storage;

use LombokClarion\Http\UploadedFile;

/**
 * Filesystem Storage rooted at one directory. Every public method takes a
 * RELATIVE path and pushes it through assertSafePath() first — the same
 * discipline as ConnectionManager::databaseKey(): validate the name at the
 * boundary once, so nothing past the boundary needs to re-reason about
 * traversal.
 *
 * The root itself is trusted configuration (bootstrap wires it to
 * storage/app); what is distrusted is every path built after boot.
 */
final class LocalStorage implements Storage
{
    /** Stored names are generated from this many random bytes (hex-encoded). */
    private const RANDOM_NAME_BYTES = 16;

    /** Extensions are lowercase alphanumerics, short. `php` is not a length problem — see store(). */
    private const EXTENSION_PATTERN = '/^[a-z0-9]{1,10}$/';

    private readonly string $root;

    public function __construct(string $root)
    {
        $real = rtrim($root, '/');
        if ($real === '') {
            throw new \InvalidArgumentException('LocalStorage root must not be empty.');
        }
        $this->root = $real;
    }

    public function put(string $path, string $contents): void
    {
        $full = $this->fullPath($path);
        $this->ensureDirectory(dirname($full));

        if (file_put_contents($full, $contents) === false) {
            throw new \RuntimeException(sprintf('Failed to write "%s".', $path));
        }
    }

    public function get(string $path): string
    {
        $full = $this->fullPath($path);

        if (!is_file($full)) {
            throw new \RuntimeException(sprintf('Storage path "%s" does not exist.', $path));
        }

        $contents = file_get_contents($full);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Failed to read "%s".', $path));
        }

        return $contents;
    }

    public function exists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    public function delete(string $path): void
    {
        $full = $this->fullPath($path);

        if (!is_file($full)) {
            throw new \RuntimeException(sprintf('Storage path "%s" does not exist.', $path));
        }

        if (!unlink($full)) {
            throw new \RuntimeException(sprintf('Failed to delete "%s".', $path));
        }
    }

    public function store(UploadedFile $file, string $directory, string $extension): string
    {
        // The extension is the CALLER's decision (typically after Rule::file()
        // proved the content type) — never the client's. It is validated all
        // the same: a caller passing through clientExtension() unvalidated
        // should hit a wall here, not a filesystem.
        if (preg_match(self::EXTENSION_PATTERN, $extension) !== 1) {
            throw new \RuntimeException(sprintf(
                'Invalid storage extension "%s": expected lowercase alphanumerics (1–10 chars), no dot.',
                $extension,
            ));
        }

        $this->assertSafePath($directory);

        $name = bin2hex(random_bytes(self::RANDOM_NAME_BYTES)) . '.' . $extension;
        $relative = $directory . '/' . $name;

        $full = $this->fullPath($relative);
        $this->ensureDirectory(dirname($full));

        // Provenance-aware move lives on UploadedFile (it owns $sapi).
        // Throws on invalid upload or failed move — no boolean to ignore.
        $file->moveTo($full);

        return $relative;
    }

    /**
     * Rejects anything that could escape or alias the root: absolute paths,
     * drive/backslash forms, `.`/`..` segments, empty segments, NUL bytes.
     * A hard error, not a normalization — a caller building `../` did not
     * mean something we can guess at.
     */
    private function assertSafePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new \RuntimeException(sprintf('Unsafe storage path "%s".', addcslashes($path, "\0")));
        }

        if ($path[0] === '/' || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new \RuntimeException(sprintf('Storage paths are relative to the root; "%s" is absolute.', $path));
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException(sprintf('Unsafe storage path "%s".', $path));
            }
        }
    }

    private function fullPath(string $path): string
    {
        $this->assertSafePath($path);

        return $this->root . '/' . $path;
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create storage directory "%s".', $dir));
        }
    }
}
