<?php

declare(strict_types=1);

namespace LombokClarion\Mail;

/**
 * Email address value object. Validates format at construction.
 */
final class Address
{
    public readonly string $email;
    public readonly ?string $name;

    public function __construct(string $email, ?string $name = null)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exceptions\MailException("Invalid email address: {$email}");
        }

        $this->email = $email;
        $this->name = $name;
    }

    public function toString(): string
    {
        if ($this->name !== null) {
            $escaped = str_replace('"', '\\"', $this->name);
            return "\"{$escaped}\" <{$this->email}>";
        }

        return $this->email;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
