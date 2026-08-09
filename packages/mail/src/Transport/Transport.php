<?php

declare(strict_types=1);

namespace LombokClarion\Mail\Transport;

use LombokClarion\Mail\Envelope;
use LombokClarion\Mail\Attachment;

/**
 * How a message reaches the recipient. Implementations: SmtpTransport,
 * SendmailTransport, LogTransport (testing), ArrayTransport (testing).
 *
 * A SES or Mailgun transport is one class implementing this interface —
 * it is never a core dependency.
 */
interface Transport
{
    /**
     * @param list<Attachment> $attachments
     */
    public function send(
        Envelope $envelope,
        string $htmlBody,
        ?string $textBody,
        array $attachments = [],
    ): void;
}
