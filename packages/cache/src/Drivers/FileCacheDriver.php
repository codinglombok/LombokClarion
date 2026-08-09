<?php

declare(strict_types=1);

namespace LombokClarion\Cache\Drivers;

use LombokClarion\Cache\Cache;
use LombokClarion\Cache\Exceptions\CacheException;

/**
 * Filesystem cache driver. Each key maps to one file in $directory.
 *
 * File format: first 10 bytes = Unix expiry timestamp (zero-padded),
 * remainder = serialized value. Reads check expiry before unserializing.
 *
 * This driver is good for single-server deployments; it is NOT distributed.
 * For multi-server, bind a Redis/Memcached driver to the Cache interface.
 */
final class FileCacheDriver implements Cache
{
    private const EXPIRY_LENGTH = 10;

    private readonly string $directory;

    public function __construct(string $directory)
    {
        $real = realpath($directory);
        if ($real === false) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new CacheException("Cannot create cache directory: {$directory}");
            }
            $real = realpath($directory);
        }

        if ($real === false || !is_writable($real)) {
            throw new CacheException("Cache directory is not writable: {$directory}");
        }

        $this->directory = $real;
    }

    public function get(string $key): mixed
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) < self::EXPIRY_LENGTH) {
            return null;
        }

        $expiry = (int) substr($raw, 0, self::EXPIRY_LENGTH);

        if ($expiry !== 0 && $expiry < time()) {
            @unlink($path);
            return null;
        }

        return unserialize(substr($raw, self::EXPIRY_LENGTH));
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $path = $this->path($key);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $expiry = str_pad((string) (time() + $ttl), self::EXPIRY_LENGTH, '0', STR_PAD_LEFT);
        $content = $expiry . serialize($value);

        // Atomic write via temp + rename
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new CacheException("Failed to write cache file: {$path}");
        }
        rename($tmp, $path);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function flush(): void
    {
        $this->deleteDirectory($this->directory, preserve: true);
    }

    public function remember(string $key, int $ttl, callable $factory): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $factory();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function increment(string $key, int $amount = 1, int $ttl = 3600): int
    {
        $current = $this->get($key);
        $new = ($current !== null ? (int) $current : 0) + $amount;
        $this->put($key, $new, $ttl);

        return $new;
    }

    public function decrement(string $key, int $amount = 1, int $ttl = 3600): int
    {
        return $this->increment($key, -$amount, $ttl);
    }

    private function path(string $key): string
    {
        // Two-level directory hash to avoid single-directory inode pressure
        $hash = sha1($key);

        return $this->directory . '/' . $hash[0] . $hash[1] . '/' . $hash . '.cache';
    }

    private function deleteDirectory(string $dir, bool $preserve = false): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->deleteDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        if (!$preserve) {
            @rmdir($dir);
        }
    }
}
