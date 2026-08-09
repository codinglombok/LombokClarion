<?php

declare(strict_types=1);

namespace LombokClarion\Mail;

/**
 * Base class for mail messages. Subclass this and implement envelope() +
 * content() to define a reusable, testable mail message.
 *
 *   class WelcomeEmail extends Mailable {
 *       public function __construct(private readonly string $username) {}
 *       public function envelope(): Envelope {
 *           return (new Envelope())->subject("Welcome, {$this->username}!");
 *       }
 *       public function content(): Content {
 *           return new Content(view: 'emails.welcome', data: ['user' => $this->username]);
 *       }
 *   }
 */
abstract class Mailable
{
    /** @var list<Attachment> */
    private array $attachments = [];

    abstract public function envelope(): Envelope;

    abstract public function content(): Content;

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    public function attach(string $path, string $filename, string $mimeType = 'application/octet-stream'): static
    {
        $this->attachments[] = new Attachment($path, $filename, $mimeType);
        return $this;
    }

    public function attachData(string $data, string $filename, string $mimeType = 'application/octet-stream'): static
    {
        $this->attachments[] = Attachment::fromData($data, $filename, $mimeType);
        return $this;
    }
}
