<?php

declare(strict_types=1);

use LombokClarion\Auth\Authenticatable;
use LombokClarion\Auth\Authenticate;
use LombokClarion\Auth\AuthManager;
use LombokClarion\Auth\Authorize;
use LombokClarion\Auth\DatabaseRoleRepository;
use LombokClarion\Auth\Exceptions\AuthException;
use LombokClarion\Auth\Gate;
use LombokClarion\Auth\InMemoryTokenStore;
use LombokClarion\Auth\Policy;
use LombokClarion\Auth\RoleRepository;
use LombokClarion\Auth\TokenIssuer;
use LombokClarion\Auth\UserProvider;
use LombokClarion\Http\Request;
use LombokClarion\Http\RequestContext;
use LombokClarion\Http\Response;
use LombokClarion\Security\Argon2idPasswordHasher;
use LombokClarion\Security\PasswordHasher;
use LombokClarion\Security\SecurityConfig;

// --- Test doubles ----------------------------------------------------------

final class TestUser implements Authenticatable
{
    public function __construct(
        private readonly string $id,
        private readonly string $hash,
    ) {
    }

    public function getAuthId(): string
    {
        return $this->id;
    }

    public function getPasswordHash(): string
    {
        return $this->hash;
    }
}

final class ArrayUserProvider implements UserProvider
{
    /** @param array<string, TestUser> $byIdentifier */
    public function __construct(private array $byIdentifier)
    {
    }

    public function findByIdentifier(string $identifier): ?Authenticatable
    {
        return $this->byIdentifier[$identifier] ?? null;
    }

    public function findById(string $id): ?Authenticatable
    {
        foreach ($this->byIdentifier as $user) {
            if ($user->getAuthId() === $id) {
                return $user;
            }
        }

        return null;
    }
}

final class ArrayRoleRepository implements RoleRepository
{
    /**
     * @param array<string, list<string>> $roles
     * @param array<string, list<string>> $permissions
     */
    public function __construct(private array $roles = [], private array $permissions = [])
    {
    }

    public function rolesFor(string $userId): array
    {
        return $this->roles[$userId] ?? [];
    }

    public function permissionsFor(string $userId): array
    {
        return $this->permissions[$userId] ?? [];
    }
}

/**
 * Fast on purpose: Argon2id at production cost turns this file into a coffee
 * break. The real hasher is exercised where it matters (SecurityTest); here the
 * subject under test is AuthManager's control flow, not the KDF.
 */
final class FakeHasher implements PasswordHasher
{
    public int $verifyCalls = 0;

    public function hash(string $plaintext): string
    {
        return 'hashed:' . $plaintext;
    }

    public function verify(string $plaintext, string $hash): bool
    {
        $this->verifyCalls++;

        return hash_equals($hash, 'hashed:' . $plaintext);
    }

    public function needsRehash(string $hash): bool
    {
        return false;
    }
}

function auth_make(?FakeHasher $hasher = null, ?InMemoryTokenStore $store = null, int $ttl = 3600): array
{
    $hasher ??= new FakeHasher();
    $users = new ArrayUserProvider([
        'ada@example.test' => new TestUser('u1', 'hashed:correct-horse'),
    ]);
    $context = new RequestContext();
    $issuer = new TokenIssuer('test-secret-value', $ttl);
    $auth = new AuthManager($users, $hasher, $issuer, $context, $store);

    return [$auth, $issuer, $context, $hasher];
}

function auth_run(object $middleware, Request $request): Response
{
    return $middleware->handle($request, fn (Request $r): Response => Response::text('handler reached', 200));
}

// --- attempt ---------------------------------------------------------------

test('attempt succeeds with correct credentials', function () {
    [$auth] = auth_make();
    $user = $auth->attempt('ada@example.test', 'correct-horse');
    assertTrue($user instanceof Authenticatable);
    assertSame('u1', $user->getAuthId());
});

test('attempt fails with a wrong password', function () {
    [$auth] = auth_make();
    assertSame(null, $auth->attempt('ada@example.test', 'wrong'));
});

test('attempt fails for an unknown user', function () {
    [$auth] = auth_make();
    assertSame(null, $auth->attempt('nobody@example.test', 'correct-horse'));
});

test('attempt hashes even for an unknown user (no user-enumeration oracle)', function () {
    $hasher = new FakeHasher();
    [$auth] = auth_make($hasher);

    $auth->attempt('nobody@example.test', 'anything');

    // The point of the dummy-hash path: an unknown user must cost a verification
    // too, or response time answers "does this account exist".
    assertSame(1, $hasher->verifyCalls);
});

test('attempt does not bind the user into the request context', function () {
    [$auth, , $context] = auth_make();
    $auth->attempt('ada@example.test', 'correct-horse');
    // Verifying a password is not logging in — the caller decides that.
    assertSame(null, $context->get(AuthManager::CONTEXT_KEY));
    assertTrue(!$auth->check());
});

// --- tokens ----------------------------------------------------------------

test('a freshly issued token verifies back to its user id', function () {
    $issuer = new TokenIssuer('s3cret', 3600);
    $token = $issuer->issue('u1');
    assertSame('u1', $issuer->verify($token));
});

test('a tampered token is rejected', function () {
    $issuer = new TokenIssuer('s3cret', 3600);
    $token = $issuer->issue('u1');
    assertSame(null, $issuer->verify($token . 'x'));

    // Flip the payload but keep the original signature: the classic forgery.
    [$id, $exp, $nonce, $sig] = explode('.', $token);
    $forged = rtrim(strtr(base64_encode('admin'), '+/', '-_'), '=') . ".$exp.$nonce.$sig";
    assertSame(null, $issuer->verify($forged));
});

test('a token signed with a different secret is rejected', function () {
    $mint = new TokenIssuer('secret-a', 3600);
    $verify = new TokenIssuer('secret-b', 3600);
    assertSame(null, $verify->verify($mint->issue('u1')));
});

test('an expired token is rejected', function () {
    $issuer = new TokenIssuer('s3cret', 3600);
    $token = $issuer->issue('u1', 1_000_000);
    assertSame('u1', $issuer->verify($token, 1_000_000 + 3599));
    assertSame(null, $issuer->verify($token, 1_000_000 + 3601));
});

test('a token expiring exactly now is rejected, not accepted', function () {
    $issuer = new TokenIssuer('s3cret', 3600);
    $token = $issuer->issue('u1', 1_000_000);
    // Boundary conditions are where auth bugs live; pin it.
    assertSame(null, $issuer->verify($token, 1_000_000 + 3600));
});

test('a user id containing dots survives a round trip', function () {
    $issuer = new TokenIssuer('s3cret', 3600);
    // The reason ids are base64url-encoded: an email is a perfectly ordinary id.
    $token = $issuer->issue('ada.lovelace@example.test');
    assertSame('ada.lovelace@example.test', $issuer->verify($token));
});

test('malformed tokens are rejected rather than throwing', function () {
    $issuer = new TokenIssuer('s3cret', 3600);
    foreach (['', 'x', 'a.b', 'a.b.c.d', '.1000.sig', 'dXNlcg.notanumber.sig', 'dXNlcg.1000.NOTHEX.sig'] as $bad) {
        assertSame(null, $issuer->verify($bad), "expected null for: $bad");
    }
});

// --- U-S2-3: per-issue nonce + TokenStore contract --------------------------

test('two issues for the same user in the same second are distinct tokens', function () {
    // Without the nonce, issue() was deterministic per (user, second): two
    // logins inside one second produced byte-identical tokens, so identical
    // tokenIds — a UNIQUE explosion (or a resurrected revoked row) in any
    // durable TokenStore. U-S2-3.
    $issuer = new TokenIssuer('s3cret', 3600);
    $a = $issuer->issue('u1', 1_000_000);
    $b = $issuer->issue('u1', 1_000_000);
    assertTrue($a !== $b);
    assertSame('u1', $issuer->verify($a, 1_000_000));
    assertSame('u1', $issuer->verify($b, 1_000_000));
});

test('revoking one same-second session leaves the other alive (per-session revocation)', function () {
    $issuer = new TokenIssuer('s3cret', 3600);
    $store = new InMemoryTokenStore();
    $a = $issuer->issue('u1', 1_000_000);
    $b = $issuer->issue('u1', 1_000_000);
    $idA = hash('sha256', $a);
    $idB = hash('sha256', $b);
    $store->remember($idA, 'u1', 1_003_600);
    $store->remember($idB, 'u1', 1_003_600);
    $store->revoke($idA);
    assertTrue($store->isRevoked($idA));
    assertTrue(!$store->isRevoked($idB));
});

test('a legacy 3-field token (pre-nonce) still verifies until it expires', function () {
    // Payload-version compatibility: tokens minted before the nonce upgrade
    // must survive the deploy. Mint one by hand in the old format.
    $secret = 's3cret';
    $issuer = new TokenIssuer($secret, 3600);
    $encodedId = rtrim(strtr(base64_encode('u1'), '+/', '-_'), '=');
    $expiry = 1_000_000 + 3600;
    $payload = $encodedId . '.' . $expiry;
    $legacy = $payload . '.' . hash_hmac('sha256', $payload, $secret);
    assertSame('u1', $issuer->verify($legacy, 1_000_000));
    assertSame(null, $issuer->verify($legacy, $expiry + 1));
});

test('TokenStore contract: remember() on a known id overwrites AND un-revokes', function () {
    // The written contract of TokenStore::remember() (U-S2-3): a re-remembered
    // tokenId is the statement "this token is valid NOW". remember → revoke →
    // remember must end not-revoked. Runs against the reference InMemory
    // implementation; any durable implementation (UPSERT with
    // revoked_at = NULL) is held to the same sequence.
    $store = new InMemoryTokenStore();
    $store->remember('t1', 'u1', 1_003_600);
    $store->revoke('t1');
    assertTrue($store->isRevoked('t1'));
    $store->remember('t1', 'u1', 1_007_200);
    assertTrue(!$store->isRevoked('t1'), 'remember() must reset the revocation mark');
});

// --- login / logout / revocation -------------------------------------------

test('login issues a token and binds the user into the context', function () {
    [$auth, , $context] = auth_make();
    $user = $auth->attempt('ada@example.test', 'correct-horse');
    $token = $auth->login($user);

    assertTrue($token !== '');
    assertSame($user, $context->get(AuthManager::CONTEXT_KEY));
    assertTrue($auth->check());
});

test('authenticateToken resolves a logged-in user back from their token', function () {
    [$auth] = auth_make();
    $token = $auth->login($auth->attempt('ada@example.test', 'correct-horse'));
    $resolved = $auth->authenticateToken($token);
    assertSame('u1', $resolved->getAuthId());
});

test('logout without a TokenStore reports that it could not revoke', function () {
    [$auth, , $context] = auth_make();
    $token = $auth->login($auth->attempt('ada@example.test', 'correct-horse'));

    // Honest return value: the context is cleared, but a signed token stays
    // valid until expiry and the framework says so instead of pretending.
    assertSame(false, $auth->logout($token));
    assertSame(null, $context->get(AuthManager::CONTEXT_KEY));
    assertTrue($auth->authenticateToken($token) instanceof Authenticatable);
});

test('logout with a TokenStore actually revokes the token', function () {
    $store = new InMemoryTokenStore();
    [$auth] = auth_make(null, $store);
    $token = $auth->login($auth->attempt('ada@example.test', 'correct-horse'));

    assertTrue($auth->authenticateToken($token) instanceof Authenticatable);
    assertSame(true, $auth->logout($token));
    assertSame(null, $auth->authenticateToken($token));
});

test('a store treats a token it never issued as revoked', function () {
    $store = new InMemoryTokenStore();
    [$auth, $issuer] = auth_make(null, $store);
    // Correctly signed, but minted outside the store: opting into a store means
    // unknown tokens are refused rather than assumed fine.
    $orphan = $issuer->issue('u1');
    assertSame(null, $auth->authenticateToken($orphan));
});

test('the token store never holds the token itself', function () {
    $store = new InMemoryTokenStore();
    [$auth] = auth_make(null, $store);
    $token = $auth->login($auth->attempt('ada@example.test', 'correct-horse'));

    // A leaked store must not be a leaked set of live sessions.
    $dumped = print_r($store, true);
    assertTrue(!str_contains($dumped, $token));
    assertTrue(str_contains($dumped, hash('sha256', $token)));
});

// --- Authenticate middleware ----------------------------------------------

test('Authenticate returns 401 when no token is present', function () {
    [$auth] = auth_make();
    $response = auth_run(new Authenticate($auth), new Request('GET', '/account'));
    assertSame(401, $response->status);
});

test('Authenticate returns 401 for a tampered token and never reaches the handler', function () {
    [$auth] = auth_make();
    $token = $auth->login($auth->attempt('ada@example.test', 'correct-horse'));
    $request = new Request('GET', '/account', [], [], [], [Authenticate::COOKIE => $token . 'x']);

    $response = auth_run(new Authenticate($auth), $request);
    assertSame(401, $response->status);
    assertTrue(!str_contains($response->body, 'handler reached'));
});

test('Authenticate accepts a cookie token and binds the user', function () {
    [$auth, , $context] = auth_make();
    $token = $auth->login($auth->attempt('ada@example.test', 'correct-horse'));
    $context->set(AuthManager::CONTEXT_KEY, null);

    $request = new Request('GET', '/account', [], [], [], [Authenticate::COOKIE => $token]);
    $response = auth_run(new Authenticate($auth), $request);

    assertSame(200, $response->status);
    assertSame('u1', $context->get(AuthManager::CONTEXT_KEY)->getAuthId());
});

test('Authenticate accepts a Bearer token', function () {
    [$auth] = auth_make();
    $token = $auth->login($auth->attempt('ada@example.test', 'correct-horse'));
    // Header keys are stored lowercase framework-wide (Request::fromGlobals
    // normalizes them; header() lowercases the lookup). Constructing a Request
    // by hand with 'Authorization' silently misses — see AUDIT-TRAIL #34.
    $request = new Request('GET', '/api/me', [], [], ['authorization' => "Bearer $token"]);

    assertSame(200, auth_run(new Authenticate($auth), $request)->status);
});

test('Authenticate 401 carries WWW-Authenticate', function () {
    [$auth] = auth_make();
    $response = auth_run(new Authenticate($auth), new Request('GET', '/account'));
    assertSame('Bearer', $response->headers['WWW-Authenticate'] ?? null);
});

// --- Authorize middleware --------------------------------------------------

test('Authorize::role grants when the user has the role', function () {
    [$auth] = auth_make();
    $auth->bind($auth->attempt('ada@example.test', 'correct-horse'));
    $roles = new ArrayRoleRepository(['u1' => ['admin']]);

    $response = auth_run(Authorize::role('admin', $auth, $roles), new Request('POST', '/admin'));
    assertSame(200, $response->status);
});

test('Authorize::role denies with 403 when the role is missing', function () {
    [$auth] = auth_make();
    $auth->bind($auth->attempt('ada@example.test', 'correct-horse'));
    $roles = new ArrayRoleRepository(['u1' => ['editor']]);

    $response = auth_run(Authorize::role('admin', $auth, $roles), new Request('POST', '/admin'));
    assertSame(403, $response->status);
    assertTrue(!str_contains($response->body, 'handler reached'));
});

test('Authorize::permission grants and denies on the permission list', function () {
    [$auth] = auth_make();
    $auth->bind($auth->attempt('ada@example.test', 'correct-horse'));
    $roles = new ArrayRoleRepository([], ['u1' => ['post.edit']]);

    assertSame(200, auth_run(Authorize::permission('post.edit', $auth, $roles), new Request('POST', '/p'))->status);
    assertSame(403, auth_run(Authorize::permission('post.delete', $auth, $roles), new Request('POST', '/p'))->status);
});

test('Authorize returns 401, not 403, when nobody is authenticated', function () {
    [$auth] = auth_make();
    $roles = new ArrayRoleRepository(['u1' => ['admin']]);
    // No identity is a different failure from insufficient rights.
    assertSame(401, auth_run(Authorize::role('admin', $auth, $roles), new Request('POST', '/admin'))->status);
});

test('Authorize does not disclose the required permission', function () {
    [$auth] = auth_make();
    $auth->bind($auth->attempt('ada@example.test', 'correct-horse'));
    $roles = new ArrayRoleRepository([], ['u1' => []]);

    $response = auth_run(Authorize::permission('billing.refund', $auth, $roles), new Request('POST', '/r'));
    assertTrue(!str_contains($response->body, 'billing.refund'));
});

// --- Gate / policies -------------------------------------------------------

final class TestPostPolicy implements Policy
{
    public function allows(string $ability, Authenticatable $user, mixed $subject = null): bool
    {
        return $ability === 'post.edit' && is_array($subject) && ($subject['author_id'] ?? null) === $user->getAuthId();
    }
}

test('Gate dispatches a defined ability to its policy', function () {
    $gate = new Gate(fn (string $class): Policy => new $class());
    $gate->define('post.edit', TestPostPolicy::class);
    $user = new TestUser('u1', 'x');

    assertTrue($gate->allows('post.edit', $user, ['author_id' => 'u1']));
    assertTrue($gate->denies('post.edit', $user, ['author_id' => 'someone-else']));
});

test('Gate throws on an undefined ability rather than silently denying', function () {
    $gate = new Gate(fn (string $class): Policy => new $class());
    $gate->define('post.edit', TestPostPolicy::class);
    // A typo must not read as "denied" — that is how a broken check passes tests.
    assertThrows(AuthException::class, fn () => $gate->allows('post.edti', new TestUser('u1', 'x')));
});

test('Gate refuses a duplicate ability definition', function () {
    $gate = new Gate(fn (string $class): Policy => new $class());
    $gate->define('post.edit', TestPostPolicy::class);
    assertThrows(AuthException::class, fn () => $gate->define('post.edit', TestPostPolicy::class));
});

test('Gate resolves policies through the injected resolver', function () {
    $resolved = [];
    $gate = new Gate(function (string $class) use (&$resolved): Policy {
        $resolved[] = $class;

        return new $class();
    });
    $gate->define('post.edit', TestPostPolicy::class);
    $gate->allows('post.edit', new TestUser('u1', 'x'), ['author_id' => 'u1']);

    assertSame([TestPostPolicy::class], $resolved);
});

test('Gate lists its abilities (auditable, not discovered)', function () {
    $gate = new Gate(fn (string $class): Policy => new $class());
    $gate->define('post.edit', TestPostPolicy::class);
    assertSame(['post.edit'], $gate->abilities());
    assertTrue($gate->has('post.edit'));
    assertTrue(!$gate->has('post.publish'));
});

// --- DatabaseRoleRepository ------------------------------------------------

function auth_rbac_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE roles (id VARCHAR(32) PRIMARY KEY, name VARCHAR(64) NOT NULL UNIQUE)');
    $pdo->exec('CREATE TABLE permissions (id VARCHAR(32) PRIMARY KEY, name VARCHAR(128) NOT NULL UNIQUE)');
    $pdo->exec('CREATE TABLE role_user (user_id VARCHAR(32), role_id VARCHAR(32), PRIMARY KEY (user_id, role_id))');
    $pdo->exec('CREATE TABLE permission_role (role_id VARCHAR(32), permission_id VARCHAR(32), PRIMARY KEY (role_id, permission_id))');

    $pdo->exec("INSERT INTO roles VALUES ('r1','admin'), ('r2','editor')");
    $pdo->exec("INSERT INTO permissions VALUES ('p1','post.edit'), ('p2','post.delete')");
    $pdo->exec("INSERT INTO role_user VALUES ('u1','r1'), ('u1','r2')");
    $pdo->exec("INSERT INTO permission_role VALUES ('r1','p1'), ('r1','p2'), ('r2','p1')");

    return $pdo;
}

test('DatabaseRoleRepository reads roles through the join table', function () {
    $repo = new DatabaseRoleRepository(auth_rbac_pdo());
    assertSame(['admin', 'editor'], $repo->rolesFor('u1'));
    assertSame([], $repo->rolesFor('nobody'));
});

test('DatabaseRoleRepository deduplicates permissions granted by two roles', function () {
    $repo = new DatabaseRoleRepository(auth_rbac_pdo());
    // u1 gets post.edit from both admin and editor — it must appear once.
    assertSame(['post.delete', 'post.edit'], $repo->permissionsFor('u1'));
});

test('DatabaseRoleRepository binds the user id (injection payload is inert)', function () {
    $repo = new DatabaseRoleRepository(auth_rbac_pdo());
    assertSame([], $repo->rolesFor("u1' OR '1'='1"));
});

// --- The real hasher, once -------------------------------------------------

test('AuthManager works against the real Argon2id hasher', function () {
    $hasher = new Argon2idPasswordHasher(new SecurityConfig());
    $users = new ArrayUserProvider([
        'real@example.test' => new TestUser('u9', $hasher->hash('s3cret-passphrase')),
    ]);
    $auth = new AuthManager($users, $hasher, new TokenIssuer('k', 3600), new RequestContext());

    assertTrue($auth->attempt('real@example.test', 's3cret-passphrase') instanceof Authenticatable);
    assertSame(null, $auth->attempt('real@example.test', 'wrong'));
});
