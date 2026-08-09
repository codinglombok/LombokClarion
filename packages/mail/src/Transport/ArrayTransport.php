<?php

declare(strict_types=1);

namespace LombokClarion\Mail\Transport;

use LombokClarion\Mail\Attachment;
use LombokClarion\Mail\Envelope;

/**
 * Captures sent messages in an array for test assertions.
 */
final class ArrayTransport implements Transport
{
    /** @var list<array{envelope: Envelope, html: string, text: ?string, attachments: list<Attachment>}> */
    private array $sent = [];

    public function send(
        Envelope $envelope,
        string $htmlBody,
        ?string $textBody,
        array $attachments = [],
    ): void {
        $this->sent[] = [
            'envelope' => $envelope,
            'html' => $htmlBody,
            'text' => $textBody,
            'attachments' => $attachments,
        ];
    }

    /**
     * @return list<array{envelope: Envelope, html: string, text: ?string, attachments: list<Attachment>}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function flush(): void
    {
        $this->sent = [];
    }

    public function assertSentCount(int $expected): void
    {
        if (count($this->sent) !== $expected) {
            throw new \RuntimeException("Expected {$expected} sent messages, got " . count($this->sent));
        }
    }
}
