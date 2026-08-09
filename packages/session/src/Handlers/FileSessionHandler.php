<?php

declare(strict_types=1);

namespace LombokClarion\Session\Handlers;

use LombokClarion\Session\Exceptions\SessionException;
use LombokClarion\Session\SessionHandler;

/**
 * File-based session handler. One file per session in $directory.
 */
final class FileSessionHandler implements SessionHandler
{
    private readonly string $directory;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new SessionException("Cannot create session directory: {$directory}");
        }
        $real = realpath($directory);
        if ($real === false || !is_writable($real)) {
            throw new SessionException("Session directory not writable: {$directory}");
        }
        $this->directory = $real;
    }

    public function read(string $id): ?string
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        $data = file_get_contents($path);
        return $data === false ? null : $data;
    }

    public function write(string $id, string $data, int $lifetime): void
    {
        $path = $this->path($id);
        file_put_contents($path, $data, LOCK_EX);
        touch($path, time() + $lifetime);
    }

    public function destroy(string $id): void
    {
        $path = $this->path($id);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function gc(int $maxLifetime): int
    {
        $count = 0;
        $threshold = time();
        $items = new \DirectoryIterator($this->directory);

        foreach ($items as $item) {
            if ($item->isDot() || !$item->isFile()) {
                continue;
            }
            // mtime is set to expiry time on write
            if ($item->getMTime() < $threshold) {
                @unlink($item->getPathname());
                $count++;
            }
        }

        return $count;
    }

    private function path(string $id): string
    {
        // Validate ID to prevent traversal
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            throw new SessionException('Invalid session ID format.');
        }
        return $this->directory . '/sess_' . $id;
    }
}
