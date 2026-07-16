<?php

declare(strict_types=1);

namespace LombokClarion\Security;

use LombokClarion\Security\Exceptions\SecurityException;

/**
 * Cost params live here, typed, rather than as magic numbers scattered
 * through app code. PasswordHasher reads this at construction and refuses
 * to run with weak parameters (fails loudly rather than silently hashing
 * insecurely) — see §6 "weak hasher cost params" audit item.
 */
final class SecurityConfig
{
    public function __construct(
        public readonly int $argon2Memory = 65536,
        public readonly int $argon2Time = 4,
        public readonly int $argon2Threads = 2,
    ) {
        if ($this->argon2Memory < 19456) {
            throw new SecurityException(
                'Argon2id memory cost below 19456 KiB (OWASP minimum) is not permitted.'
            );
        }
        if ($this->argon2Time < 2) {
            throw new SecurityException('Argon2id time cost below 2 is not permitted.');
        }
    }
}
