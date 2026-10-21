<?php

declare(strict_types=1);

namespace LombokClarion\Security;

use LombokClarion\Security\Exceptions\SecurityException;

/**
 * No app code ever calls password_hash()/password_verify() directly —
 * this is the single, injected implementation, so cost params live in one
 * typed place (SecurityConfig) instead of being copy-pasted at every call
 * site (design spec §6).
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
        self::assertArgon2idAvailable();

        return password_hash($plaintext, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(string $plaintext, string $hash): bool
    {
        self::assertArgon2idAvailable();

        return password_verify($plaintext, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        self::assertArgon2idAvailable();

        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }

    /**
     * PASSWORD_ARGON2ID only exists when PHP was compiled against libargon2.
     * Most distro builds and shivammathur/setup-php ship it, but minimal
     * static binaries may not — fail with an actionable message instead of
     * a bare "Undefined constant" fatal at the worst possible moment
     * (first login attempt).
     */
    private static function assertArgon2idAvailable(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new SecurityException(
                'PASSWORD_ARGON2ID is not available: this PHP build was compiled without libargon2. '
                . 'Rebuild PHP with Argon2 support (or install the argon2 extension) — the framework '
                . 'deliberately does not silently downgrade to a weaker hash algorithm.'
            );
        }
    }
}
