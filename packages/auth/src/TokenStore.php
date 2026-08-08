<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

/**
 * The seam that buys back revocation from stateless tokens (§Stage 9).
 *
 * The default is deliberately no store at all: a signed token needs no lookup,
 * which is the whole point. Supply one only when you need logout to take effect
 * server-side or want to cut a stolen token short — and understand what you are
 * buying: a database round-trip on every authenticated request, which is exactly
 * the cost stateless tokens exist to avoid. That is a real trade, so the
 * framework makes you choose it rather than choosing for you.
 */
interface TokenStore
{
    /**
     * Record that this token exists and is currently valid.
     *
     * CONTRACT (U-S2-3): a $tokenId this store already knows MUST be fully
     * overwritten — userId, expiresAt, AND any revoked flag reset to
     * not-revoked. remember() is the statement "this token is valid NOW";
     * whatever the store believed before is superseded. Colliding tokenIds
     * are a normal event, not an edge case: TokenIssuer without a per-issue
     * nonce is deterministic per (user, second), so logout → login inside
     * one second re-remembers the SAME id that was just revoked. A durable
     * implementation must therefore be an UPSERT that also clears the
     * revocation mark (e.g. `ON CONFLICT(token_id) DO UPDATE SET user_id,
     * expires_at, revoked_at = NULL`) — a plain INSERT breaks on UNIQUE, and
     * INSERT ... DO NOTHING silently leaves the token revoked after a
     * successful login. InMemoryTokenStore's array overwrite is the
     * reference semantics; TokenStoreContractTest pins them for any
     * implementation.
     */
    public function remember(string $tokenId, string $userId, int $expiresAt): void;

    /**
     * Has this token been revoked (or never issued through this store)?
     */
    public function isRevoked(string $tokenId): bool;

    public function revoke(string $tokenId): void;
}
