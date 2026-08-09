<?php

declare(strict_types=1);

namespace LombokClarion\Mail\Transport;

use LombokClarion\Mail\Address;
use LombokClarion\Mail\Attachment;
use LombokClarion\Mail\Envelope;
use LombokClarion\Log\Logger;
use LombokClarion\Log\LogLevel;

/**
 * Logs messages instead of sending them. Use in development/testing.
 */
final class LogTransport implements Transport
{
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    public function send(
        Envelope $envelope,
        string $htmlBody,
        ?string $textBody,
        array $attachments = [],
    ): void {
        $this->logger->log(LogLevel::Info, 'Mail sent (log transport)', [
            'from' => $envelope->from?->email,
            'to' => array_map(fn (Address $a) => $a->email, $envelope->to),
            'cc' => array_map(fn (Address $a) => $a->email, $envelope->cc),
            'subject' => $envelope->subject,
            'attachments' => count($attachments),
            'html_length' => strlen($htmlBody),
            'text_length' => $textBody !== null ? strlen($textBody) : 0,
        ]);
    }
}
