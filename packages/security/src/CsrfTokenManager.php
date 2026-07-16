<?php

declare(strict_types=1);

namespace LombokClarion\Security;

/**
 * Tokens are HMAC-signed nonces, not opaque IDs looked up in a session
 * store — consistent with §5's "no assumption of a persistent process".
 * Used with the double-submit-cookie pattern: ValidateCsrf compares the
 * cookie value against a value the client also sent back in a header or
 * form field, and additionally checks the HMAC signature so a value can't
 * be forged without knowing the secret.
 */
final class CsrfTokenManager
{
    public function __construct(private readonly string $secret)
    {
    }

    public function generate(): string
    {
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $nonce, $this->secret);
        return "$nonce.$signature";
    }

    public function isValid(string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        [$nonce, $signature] = $parts;
        $expected = hash_hmac('sha256', $nonce, $this->secret);

        return hash_equals($expected, $signature);
    }
}
