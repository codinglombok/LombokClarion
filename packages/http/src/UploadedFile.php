<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * Immutable uploaded-file value object. Like Request, it is constructed
 * explicitly (by Request::fromGlobals() via fromFilesArray(), or by hand in
 * tests) — never read lazily out of a superglobal.
 *
 * Trust boundaries are named, not implied:
 *   - clientName / clientMimeType are what the CLIENT claimed. They are data
 *     for display and nothing else — storage paths never use them (see
 *     LocalStorage::store()) and mime validation re-derives the type from
 *     file CONTENT (Rule::file()), not from this header.
 *   - $sapi records HOW the tmp file came to exist. true only when built
 *     from a real PHP upload ($_FILES): moving it uses move_uploaded_file(),
 *     which re-checks provenance. false (tests, CLI tooling) moves with
 *     rename(). An explicit constructor flag instead of runtime guessing,
 *     so the test path is the documented path, not a bypass.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $clientName,
        public readonly string $clientMimeType,
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly int $error = UPLOAD_ERR_OK,
        private readonly bool $sapi = false,
    ) {
    }

    /**
     * Normalizes a $_FILES-shaped array into field => UploadedFile or
     * field => list<UploadedFile> (one level of `name="photos[]"` — PHP's
     * transposed shape is flattened here so nobody else ever sees it).
     * Deeper nesting is out of vocabulary and skipped.
     *
     * @param array<string, array<string, mixed>> $files
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    public static function fromFilesArray(array $files, bool $sapi = true): array
    {
        $out = [];
        foreach ($files as $field => $info) {
            if (!is_array($info) || !array_key_exists('tmp_name', $info)) {
                continue;
            }

            if (is_string($info['tmp_name'])) {
                $out[$field] = new self(
                    clientName: (string) ($info['name'] ?? ''),
                    clientMimeType: (string) ($info['type'] ?? ''),
                    tmpPath: $info['tmp_name'],
                    size: (int) ($info['size'] ?? 0),
                    error: (int) ($info['error'] ?? UPLOAD_ERR_NO_FILE),
                    sapi: $sapi,
                );
                continue;
            }

            if (is_array($info['tmp_name'])) {
                $list = [];
                foreach ($info['tmp_name'] as $i => $tmp) {
                    if (!is_string($tmp)) {
                        continue; // deeper nesting: not vocabulary
                    }
                    $list[] = new self(
                        clientName: (string) ($info['name'][$i] ?? ''),
                        clientMimeType: (string) ($info['type'][$i] ?? ''),
                        tmpPath: $tmp,
                        size: (int) ($info['size'][$i] ?? 0),
                        error: (int) ($info['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                        sapi: $sapi,
                    );
                }
                $out[$field] = $list;
            }
        }

        return $out;
    }

    /** Upload completed AND the tmp file is really there. */
    public function isValid(): bool
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            return false;
        }

        return $this->sapi ? is_uploaded_file($this->tmpPath) : is_file($this->tmpPath);
    }

    public function isSapiUpload(): bool
    {
        return $this->sapi;
    }

    /**
     * Moves the tmp file to $destination, honoring provenance: a real SAPI
     * upload moves via move_uploaded_file() (which re-verifies the file came
     * from THIS request), anything else via rename(). The move lives here —
     * not in storage — because $sapi is this object's private knowledge;
     * exposing the flag and letting callers pick the function would turn a
     * checked invariant back into a convention.
     *
     * Single-shot: the tmp file is gone afterwards, a second call fails.
     *
     * @throws \RuntimeException when the upload is invalid or the move fails
     */
    public function moveTo(string $destination): void
    {
        if (!$this->isValid()) {
            throw new \RuntimeException(sprintf(
                'Cannot move upload "%s": error code %d or tmp file missing.',
                $this->clientName,
                $this->error,
            ));
        }

        $moved = $this->sapi
            ? move_uploaded_file($this->tmpPath, $destination)
            : rename($this->tmpPath, $destination);

        if (!$moved) {
            throw new \RuntimeException(sprintf('Failed to move upload to "%s".', $destination));
        }
    }

    /**
     * Lowercased extension of the CLIENT-claimed filename — untrusted, for
     * display or as a *suggestion* only. Storage decides the real extension
     * explicitly (LocalStorage::store() validates it); never build a path
     * from this without that validation.
     */
    public function clientExtension(): string
    {
        $ext = pathinfo($this->clientName, PATHINFO_EXTENSION);

        return strtolower($ext);
    }
}
