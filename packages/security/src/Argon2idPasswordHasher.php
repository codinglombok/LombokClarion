<?php

declare(strict_types=1);

namespace LombokClarion\Security;

/**
 * No app code ever calls password_hash()/password_verify() directly —
 * this is the single, injected implementation, so cost params live in one
 * typed place (SecurityConfig) instead of being copy-pasted at every call
 * site (master prompt §6).
 */
final class Argon2idPasswordHasher implements PasswordHasher
{
    private readonly array $options;

    public function __construct(private readonly SecurityConfig $config)
    {
        $this->options = [
            'memory_cost' => $this->config->argon2Memory,
            'time_cost' => $this->config->argon2Time,
            'threads' => $this->config->argon2Threads,
        ];
    }

    public function hash(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(string $plaintext, string $hash): bool
    {
        return password_verify($plaintext, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }
}
