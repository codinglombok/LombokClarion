<?php

declare(strict_types=1);

namespace LombokClarion\Security;

/**
 * @template T of string
 *
 * Wrap an entity field's type in Encrypted, e.g.:
 *
 *   final class Customer {
 *       public function __construct(
 *           public readonly string $name,
 *           public readonly Encrypted $taxId,
 *       ) {}
 *   }
 *
 * — so "this field is encrypted at rest" is visible in the entity's own
 * type declaration, not buried in a repository or a database column
 * annotation (master prompt §6).
 */
final class Encrypted
{
    private function __construct(private readonly string $ciphertext)
    {
    }

    public static function fromPlaintext(string $plaintext, Encrypter $encrypter): self
    {
        return new self($encrypter->encrypt($plaintext));
    }

    public static function fromCiphertext(string $ciphertext): self
    {
        return new self($ciphertext);
    }

    public function reveal(Encrypter $encrypter): string
    {
        return $encrypter->decrypt($this->ciphertext);
    }

    public function ciphertext(): string
    {
        return $this->ciphertext;
    }
}
