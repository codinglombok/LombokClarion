<?php

declare(strict_types=1);

namespace LombokClarion\Mail\Transport;

use LombokClarion\Mail\Address;
use LombokClarion\Mail\Attachment;
use LombokClarion\Mail\Envelope;
use LombokClarion\Mail\Exceptions\MailException;

/**
 * Sends mail via the local sendmail binary (or compatible MTA).
 * Uses PHP's mail() function under the hood.
 */
final class SendmailTransport implements Transport
{
    public function __construct(
        private readonly string $sendmailPath = '/usr/sbin/sendmail -bs',
    ) {
    }

    public function send(
        Envelope $envelope,
        string $htmlBody,
        ?string $textBody,
        array $attachments = [],
    ): void {
        $from = $envelope->from ?? throw new MailException('Envelope has no sender.');

        $headers = [
            'From' => $from->toString(),
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ];

        if (!empty($envelope->cc)) {
            $headers['Cc'] = implode(', ', array_map(fn (Address $a) => $a->toString(), $envelope->cc));
        }

        if ($envelope->replyTo !== null) {
            $headers['Reply-To'] = $envelope->replyTo->toString();
        }

        $to = implode(', ', array_map(fn (Address $a) => $a->toString(), $envelope->to));
        $subject = '=?UTF-8?B?' . base64_encode($envelope->subject) . '?=';

        $headerString = '';
        foreach ($headers as $name => $value) {
            $headerString .= "{$name}: {$value}\r\n";
        }

        $body = chunk_split(base64_encode($htmlBody), 76, "\r\n");

        $result = mail($to, $subject, $body, $headerString, "-f{$from->email}");

        if (!$result) {
            throw new MailException('mail() returned false — check sendmail configuration.');
        }
    }
}
