<?php

declare(strict_types=1);

namespace LombokClarion\Storage;

use LombokClarion\Http\UploadedFile;

/**
 * File storage behind relative paths. Pure persistence: WHAT may be stored
 * (mime, size) is validation's job (Rule::file()); WHERE and HOW it lands is
 * this package's job. No cloud drivers here — S3/GCS would be separate
 * packages depending on this interface, never core dependencies.
 *
 * Every $path is relative to the store's root and is validated against
 * traversal by implementations (see LocalStorage::assertSafePath()). Missing
 * files are hard errors, not nulls: get()/delete() on a path that is not
 * there is a bug in the caller, and exists() is one call away.
 */
interface Storage
{
    public function put(string $path, string $contents): void;

    /** @throws \RuntimeException when the path does not exist */
    public function get(string $path): string;

    public function exists(string $path): bool;

    /** @throws \RuntimeException when the path does not exist */
    public function delete(string $path): void;

    /**
     * Persists an uploaded file under $directory and returns the stored
     * relative path. The stored NAME is generated (random), never derived
     * from the client filename; $extension is explicit and validated —
     * the caller decides it (typically after Rule::file() proved the real
     * content type), the client never does.
     *
     * @throws \RuntimeException invalid upload, bad extension, or move failure
     */
    public function store(UploadedFile $file, string $directory, string $extension): string;
}
