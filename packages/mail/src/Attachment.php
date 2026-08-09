<?php

declare(strict_types=1);

namespace LombokClarion\Mail;

/**
 * File attachment value object.
 */
final class Attachment
{
    public function __construct(
        public readonly string $path,
        public readonly string $filename,
        public readonly string $mimeType = 'application/octet-stream',
    ) {
        if (!is_readable($this->path)) {
            throw new Exceptions\MailException("Attachment file not readable: {$this->path}");
        }
    }

    /**
     * Create an attachment from raw content (no file on disk).
     */
    public static function fromData(string $data, string $filename, string $mimeType = 'application/octet-stream'): self
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lc_mail_');
        file_put_contents($tmp, $data);

        return new self($tmp, $filename, $mimeType);
    }

    public function content(): string
    {
        return file_get_contents($this->path);
    }

    public function base64Content(): string
    {
        return base64_encode($this->content());
    }

    public function size(): int
    {
        return filesize($this->path);
    }
}
