<?php

declare(strict_types=1);

namespace LombokClarion\Mail;

/**
 * Message envelope: from, to, cc, bcc, replyTo, subject.
 * Immutable builder pattern — every method returns a new Envelope.
 */
final class Envelope
{
    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     */
    public function __construct(
        public readonly ?Address $from = null,
        public readonly array $to = [],
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly ?Address $replyTo = null,
        public readonly string $subject = '',
    ) {
    }

    public function from(string $email, ?string $name = null): self
    {
        return new self(new Address($email, $name), $this->to, $this->cc, $this->bcc, $this->replyTo, $this->subject);
    }

    public function to(string $email, ?string $name = null): self
    {
        return new self($this->from, [...$this->to, new Address($email, $name)], $this->cc, $this->bcc, $this->replyTo, $this->subject);
    }

    public function cc(string $email, ?string $name = null): self
    {
        return new self($this->from, $this->to, [...$this->cc, new Address($email, $name)], $this->bcc, $this->replyTo, $this->subject);
    }

    public function bcc(string $email, ?string $name = null): self
    {
        return new self($this->from, $this->to, $this->cc, [...$this->bcc, new Address($email, $name)], $this->replyTo, $this->subject);
    }

    public function replyTo(string $email, ?string $name = null): self
    {
        return new self($this->from, $this->to, $this->cc, $this->bcc, new Address($email, $name), $this->subject);
    }

    public function subject(string $subject): self
    {
        return new self($this->from, $this->to, $this->cc, $this->bcc, $this->replyTo, $subject);
    }
}
