<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

/**
 * Reference implementation, and what the tests use. Per-process only, so it is
 * useful for a single-process runtime (Swoole) or a test — not for FPM, where
 * every request is a fresh process and nothing would ever be remembered.
 * DatabaseTokenStore is the durable counterpart.
 */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, array{userId: string, expiresAt: int, revoked: bool}> */
    private array $tokens = [];

    public function remember(string $tokenId, string $userId, int $expiresAt): void
    {
        $this->tokens[$tokenId] = ['userId' => $userId, 'expiresAt' => $expiresAt, 'revoked' => false];
    }

    public function isRevoked(string $tokenId): bool
    {
        // Unknown tokens count as revoked. Once you have opted into a store, a
        // token it has never heard of is not "probably fine" — it is either
        // forged, or issued before the store existed, and neither should pass.
        if (!isset($this->tokens[$tokenId])) {
            return true;
        }

        return $this->tokens[$tokenId]['revoked'];
    }

    public function revoke(string $tokenId): void
    {
        if (isset($this->tokens[$tokenId])) {
            $this->tokens[$tokenId]['revoked'] = true;
        }
    }
}
