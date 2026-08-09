<?php

declare(strict_types=1);

namespace LombokClarion\Mail\Transport;

use LombokClarion\Mail\Address;
use LombokClarion\Mail\Attachment;
use LombokClarion\Mail\Envelope;
use LombokClarion\Mail\Exceptions\MailException;

/**
 * SMTP transport using PHP's stream_socket_client. Supports STARTTLS,
 * PLAIN/LOGIN auth, and multipart MIME with attachments.
 *
 * No vendor SDK dependency — this is raw SMTP protocol over a socket.
 */
final class SmtpTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly string $encryption = 'tls', // 'tls', 'ssl', 'none'
        private readonly int $timeout = 30,
    ) {
    }

    public function send(
        Envelope $envelope,
        string $htmlBody,
        ?string $textBody,
        array $attachments = [],
    ): void {
        if (empty($envelope->to)) {
            throw new MailException('Envelope has no recipients.');
        }

        $this->connect();

        try {
            $from = $envelope->from ?? throw new MailException('Envelope has no sender.');

            $this->command("MAIL FROM:<{$from->email}>", 250);

            foreach (array_merge($envelope->to, $envelope->cc, $envelope->bcc) as $recipient) {
                $this->command("RCPT TO:<{$recipient->email}>", 250);
            }

            $this->command('DATA', 354);

            $message = $this->buildMessage($envelope, $htmlBody, $textBody, $attachments);
            $this->raw($message . "\r\n.\r\n");
            $this->readResponse(250);
        } finally {
            $this->disconnect();
        }
    }

    private function connect(): void
    {
        $protocol = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $address = $protocol . $this->host . ':' . $this->port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $this->socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($this->socket === false) {
            throw new MailException("SMTP connection failed: [{$errno}] {$errstr}");
        }

        stream_set_timeout($this->socket, $this->timeout);

        $this->readResponse(220);
        $this->command('EHLO ' . gethostname(), 250);

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new MailException('STARTTLS negotiation failed.');
            }
            $this->command('EHLO ' . gethostname(), 250);
        }

        if ($this->username !== null && $this->password !== null) {
            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode($this->username), 334);
            $this->command(base64_encode($this->password), 235);
        }
    }

    private function disconnect(): void
    {
        if ($this->socket !== null) {
            @fwrite($this->socket, "QUIT\r\n");
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function command(string $command, int $expectedCode): string
    {
        $this->raw($command . "\r\n");
        return $this->readResponse($expectedCode);
    }

    private function raw(string $data): void
    {
        if ($this->socket === null) {
            throw new MailException('Not connected to SMTP server.');
        }

        $written = @fwrite($this->socket, $data);
        if ($written === false) {
            throw new MailException('Failed to write to SMTP socket.');
        }
    }

    private function readResponse(int $expectedCode): string
    {
        if ($this->socket === null) {
            throw new MailException('Not connected to SMTP server.');
        }

        $response = '';
        while (true) {
            $line = fgets($this->socket, 4096);
            if ($line === false) {
                throw new MailException('SMTP server closed connection unexpectedly.');
            }
            $response .= $line;
            // Multi-line response: code followed by '-' continues, code followed by ' ' ends
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new MailException("SMTP error: expected {$expectedCode}, got {$code}. Response: " . trim($response));
        }

        return $response;
    }

    /**
     * @param list<Attachment> $attachments
     */
    private function buildMessage(Envelope $envelope, string $htmlBody, ?string $textBody, array $attachments): string
    {
        $boundary = bin2hex(random_bytes(16));
        $headers = [];

        $from = $envelope->from ?? throw new MailException('No sender.');
        $headers[] = 'From: ' . $from->toString();
        $headers[] = 'To: ' . implode(', ', array_map(fn (Address $a) => $a->toString(), $envelope->to));

        if (!empty($envelope->cc)) {
            $headers[] = 'Cc: ' . implode(', ', array_map(fn (Address $a) => $a->toString(), $envelope->cc));
        }

        if ($envelope->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $envelope->replyTo->toString();
        }

        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($envelope->subject) . '?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . gethostname() . '>';

        $hasAttachments = !empty($attachments);

        if ($hasAttachments) {
            $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
        } elseif ($textBody !== null) {
            $altBoundary = bin2hex(random_bytes(16));
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"";
        } else {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n";

        // Dot-stuffing for SMTP DATA
        $encodeBody = function (string $body): string {
            return chunk_split(base64_encode($body), 76, "\r\n");
        };

        if ($hasAttachments) {
            $altBoundary = bin2hex(random_bytes(16));

            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";

            if ($textBody !== null) {
                $message .= "--{$altBoundary}\r\n";
                $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= $encodeBody($textBody) . "\r\n";
            }

            $message .= "--{$altBoundary}\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= $encodeBody($htmlBody) . "\r\n";
            $message .= "--{$altBoundary}--\r\n";

            foreach ($attachments as $att) {
                $message .= "--{$boundary}\r\n";
                $message .= "Content-Type: {$att->mimeType}; name=\"{$att->filename}\"\r\n";
                $message .= "Content-Disposition: attachment; filename=\"{$att->filename}\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= $att->base64Content() . "\r\n";
            }

            $message .= "--{$boundary}--";
        } elseif ($textBody !== null) {
            $message .= "--{$altBoundary}\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= $encodeBody($textBody) . "\r\n";

            $message .= "--{$altBoundary}\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= $encodeBody($htmlBody) . "\r\n";
            $message .= "--{$altBoundary}--";
        } else {
            $message .= $encodeBody($htmlBody);
        }

        return $message;
    }
}
