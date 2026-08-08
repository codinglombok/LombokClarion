<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

/**
 * Session tokens are HMAC-signed claims, not opaque IDs looked up in a session
 * store — the same reasoning as CsrfTokenManager and §5's "no assumption of a
 * persistent process": a token minted by one worker must be verifiable by any
 * other, including one that started thirty seconds ago in a different container.
 *
 * Format: `base64url(userId).expiry.nonce.signature`
 *
 * The base64url encoding is not decoration. A user id is application-defined —
 * an email, a UUID, a tenant-scoped key — and any of those may contain a `.`,
 * which would make the token ambiguous to parse and, worse, make it parseable
 * in more than one way. Encoding the id first guarantees a fixed field count.
 *
 * The nonce (U-S2-3) is 8 random bytes, hex, signed along with the rest. It
 * exists because without it issue() is deterministic per (user, second): two
 * logins in the same second produced byte-identical tokens, so identical
 * tokenIds downstream — which made a durable TokenStore explode on UNIQUE at
 * login, or worse, resurrect a just-revoked row. With the nonce, every issue
 * is unique and revocation granularity becomes per-session: logging out one
 * device no longer kills another session that happened to be minted in the
 * same second. Verification still accepts the legacy 3-field format
 * (issued before the nonce existed) so live tokens survive the upgrade; that
 * acceptance can be dropped once every pre-upgrade token has expired.
 *
 * What this design does NOT give you is revocation: a signed claim stays valid
 * until it expires, because nothing is consulted to verify it. If you need
 * logout to invalidate a token server-side, supply a TokenStore to AuthManager;
 * see that class and RevocableTokenStore for the trade-off.
 */
final class TokenIssuer
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds = 7200,
    ) {
    }

    public function issue(string $userId, ?int $now = null): string
    {
        $now ??= time();
        $expiry = $now + $this->ttlSeconds;
        $nonce = bin2hex(random_bytes(8));
        $payload = $this->encodeId($userId) . '.' . $expiry . '.' . $nonce;

        return $payload . '.' . $this->sign($payload);
    }

    /**
     * Returns the user id when the token is intact and unexpired, null otherwise.
     * Returning null rather than throwing is deliberate: an invalid token is an
     * ordinary condition on a public endpoint, not an exceptional one, and the
     * caller (Authenticate) must not be able to forget to catch it.
     */
    public function verify(string $token, ?int $now = null): ?string
    {
        $now ??= time();
        $parts = explode('.', $token);

        // 4 fields = current format (with nonce); 3 fields = legacy format,
        // still accepted so tokens issued before the nonce upgrade keep
        // verifying until they expire (payload-version compatibility, U-S2-3).
        if (count($parts) === 4) {
            [$encodedId, $expiry, $nonce, $signature] = $parts;

            if ($encodedId === '' || $signature === '' || !ctype_digit($expiry) || !ctype_xdigit($nonce)) {
                return null;
            }

            $signedPayload = $encodedId . '.' . $expiry . '.' . $nonce;
        } elseif (count($parts) === 3) {
            [$encodedId, $expiry, $signature] = $parts;

            if ($encodedId === '' || $signature === '' || !ctype_digit($expiry)) {
                return null;
            }

            $signedPayload = $encodedId . '.' . $expiry;
        } else {
            return null;
        }

        $expected = $this->sign($signedPayload);

        // Signature first, and always with hash_equals: a plain === would leak
        // how many leading bytes were correct through its early return.
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        if ((int) $expiry <= $now) {
            return null;
        }

        $userId = $this->decodeId($encodedId);

        return $userId === '' ? null : $userId;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }

    private function encodeId(string $userId): string
    {
        return rtrim(strtr(base64_encode($userId), '+/', '-_'), '=');
    }

    private function decodeId(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
