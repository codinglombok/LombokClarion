<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

use LombokClarion\Http\RequestContext;
use LombokClarion\Security\PasswordHasher;

/**
 * attempt / login / logout, built on the PasswordHasher and RequestContext that
 * already exist rather than on anything new.
 *
 * There is no `Auth::user()`. The authenticated user lives in the request's
 * RequestContext and is read from there, because a static accessor would be a
 * global that silently changes meaning under Swoole (where several requests
 * share a process) and would make every test that touches auth order-dependent.
 * If you want the current user, inject RequestContext — or this class — and ask.
 */
final class AuthManager
{
    public const CONTEXT_KEY = 'auth.user';

    /**
     * A real Argon2id hash of a value nobody can supply. See attempt().
     */
    private ?string $dummyHash = null;

    public function __construct(
        private readonly UserProvider $users,
        private readonly PasswordHasher $hasher,
        private readonly TokenIssuer $tokens,
        private readonly RequestContext $context,
        private readonly ?TokenStore $store = null,
    ) {
    }

    /**
     * Verify credentials. Returns the user, or null — never throws, and never
     * says which half was wrong.
     *
     * Note the work done on the "user not found" path: we verify the supplied
     * password against a throwaway hash anyway. Without it, a missing user
     * returns in microseconds while a wrong password costs a full Argon2id
     * verification, and that difference is readable over the network — it turns
     * the login form into an oracle for "does this email have an account here",
     * which for plenty of sites (a clinic, a dating app, an employer) is itself
     * the sensitive fact. Pair this with RateLimit on the route; neither one
     * substitutes for the other.
     */
    public function attempt(string $identifier, string $password): ?Authenticatable
    {
        $user = $this->users->findByIdentifier($identifier);

        if ($user === null) {
            $this->hasher->verify($password, $this->dummyHash());

            return null;
        }

        if (!$this->hasher->verify($password, $user->getPasswordHash())) {
            return null;
        }

        return $user;
    }

    /**
     * Mint a token for a user and bind them into this request's context.
     * Returns the token; handing it to the client (cookie, JSON body, header)
     * is the caller's decision, not ours.
     */
    public function login(Authenticatable $user, ?int $now = null): string
    {
        $now ??= time();
        $token = $this->tokens->issue($user->getAuthId(), $now);

        $this->store?->remember(
            $this->tokenId($token),
            $user->getAuthId(),
            $this->expiryOf($token) ?? $now,
        );

        $this->bind($user);

        return $token;
    }

    /**
     * Mark this request as authenticated as $user, without minting anything.
     * Authenticate uses this after verifying an existing token: the user is
     * already logged in, they are simply proving it again on a new request.
     * One place where the context is written, so "who is the current user"
     * has exactly one answer and one setter.
     */
    public function bind(Authenticatable $user): void
    {
        $this->context->set(self::CONTEXT_KEY, $user);
    }

    /**
     * Revoke a token, if a TokenStore is configured.
     *
     * With no store this clears the request context and returns false: a signed
     * token cannot be un-signed, so the token remains valid until it expires and
     * the client is responsible for dropping it. That is the honest behaviour of
     * stateless tokens, and it is reported rather than papered over — a logout
     * that silently does nothing server-side is worse than one that tells you.
     */
    public function logout(?string $token = null): bool
    {
        $this->context->set(self::CONTEXT_KEY, null);

        if ($this->store === null || $token === null) {
            return false;
        }

        $this->store->revoke($this->tokenId($token));

        return true;
    }

    /**
     * Resolve a token back to a user: signature, expiry, revocation, existence.
     * Any failure is null — the caller cannot accidentally treat "expired" or
     * "revoked" as a different, softer kind of failure.
     */
    public function authenticateToken(string $token, ?int $now = null): ?Authenticatable
    {
        $userId = $this->tokens->verify($token, $now);

        if ($userId === null) {
            return null;
        }

        if ($this->store !== null && $this->store->isRevoked($this->tokenId($token))) {
            return null;
        }

        return $this->users->findById($userId);
    }

    public function user(): ?Authenticatable
    {
        $user = $this->context->get(self::CONTEXT_KEY);

        return $user instanceof Authenticatable ? $user : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The store never sees the token itself. A stolen token database would
     * otherwise be a stolen set of live sessions; hashing means it is a set of
     * useless digests, for the same reason password tables store hashes.
     */
    private function tokenId(string $token): string
    {
        return hash('sha256', $token);
    }

    private function expiryOf(string $token): ?int
    {
        $parts = explode('.', $token);

        return count($parts) === 3 && ctype_digit($parts[1]) ? (int) $parts[1] : null;
    }

    private function dummyHash(): string
    {
        // Computed once per process, lazily: hashing costs real CPU by design,
        // and an app that never sees a failed login should never pay for it.
        return $this->dummyHash ??= $this->hasher->hash(bin2hex(random_bytes(32)));
    }
}
