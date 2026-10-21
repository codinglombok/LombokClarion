<?php

declare(strict_types=1);

namespace Tests\Framework;

use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Security\AesGcmEncrypter;
use LombokClarion\Security\Argon2idPasswordHasher;
use LombokClarion\Security\CsrfTokenManager;
use LombokClarion\Security\InMemoryRateLimitStore;
use LombokClarion\Security\RateLimit;
use LombokClarion\Security\SecurityConfig;
use LombokClarion\Security\Exceptions\SecurityException;
use LombokClarion\Security\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private function hasher(): Argon2idPasswordHasher
    {
        // Minimum allowed cost so the suite stays fast; still Argon2id.
        return new Argon2idPasswordHasher(new SecurityConfig(19456, 2, 1));
    }

    /**
     * Minimal/static PHP builds may lack libargon2 — skip rather than fail,
     * CI (setup-php) always ships it.
     */
    private function requiresArgon2id(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('libargon2 not compiled into this PHP build');
        }
    }

    public function testPasswordHashAndVerifyRoundTrip(): void
    {
        $this->requiresArgon2id();

        $hasher = $this->hasher();
        $hash = $hasher->hash('correct horse battery staple');

        $this->assertNotSame('correct horse battery staple', $hash);
        $this->assertTrue($hasher->verify('correct horse battery staple', $hash));
        $this->assertFalse($hasher->verify('wrong password', $hash));
    }

    public function testPasswordHashIsSaltedPerCall(): void
    {
        $this->requiresArgon2id();

        $hasher = $this->hasher();

        $this->assertNotSame($hasher->hash('same-input'), $hasher->hash('same-input'));
    }

    public function testNeedsRehashRejectsWeakerHash(): void
    {
        $this->requiresArgon2id();

        $hasher = $this->hasher();
        $weak = password_hash('x', PASSWORD_BCRYPT);

        $this->assertTrue($hasher->needsRehash($weak));
        $this->assertFalse($hasher->needsRehash($hasher->hash('x')));
    }

    public function testSecurityConfigRejectsSubMinimumMemory(): void
    {
        $this->expectException(SecurityException::class);
        new SecurityConfig(1024, 2, 1);
    }

    public function testCsrfTokenRoundTrip(): void
    {
        $tokens = new CsrfTokenManager('unit-test-secret');
        $token = $tokens->generate();

        $this->assertTrue($tokens->isValid($token));
        $this->assertFalse($tokens->isValid($token . 'tampered'));
        $this->assertFalse($tokens->isValid('garbage'));
    }

    public function testCsrfTokensAreSingleUseSecretBound(): void
    {
        $a = new CsrfTokenManager('secret-a');
        $b = new CsrfTokenManager('secret-b');

        $this->assertFalse($b->isValid($a->generate()));
    }

    public function testAesGcmEncrypterRoundTrip(): void
    {
        $encrypter = new AesGcmEncrypter(random_bytes(32));
        $ciphertext = $encrypter->encrypt('attack at dawn');

        $this->assertSame('attack at dawn', $encrypter->decrypt($ciphertext));
        $this->assertNotSame($ciphertext, $encrypter->encrypt('attack at dawn')); // random IV
    }

    public function testAesGcmEncrypterDetectsTampering(): void
    {
        $encrypter = new AesGcmEncrypter(random_bytes(32));
        $ciphertext = $encrypter->encrypt('attack at dawn');
        $bytes = base64_decode($ciphertext, true);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0xFF);

        $this->expectException(SecurityException::class);
        $encrypter->decrypt(base64_encode($bytes));
    }

    public function testAesGcmEncrypterRejectsWrongKey(): void
    {
        $encrypter = new AesGcmEncrypter(random_bytes(32));
        $ciphertext = $encrypter->encrypt('classified');
        $other = new AesGcmEncrypter(random_bytes(32));

        $this->expectException(SecurityException::class);
        $other->decrypt($ciphertext);
    }

    public function testRateLimitMiddlewareReturns429WhenExhausted(): void
    {
        $store = new InMemoryRateLimitStore();
        $middleware = RateLimit::perMinute(2, $store);
        $request = new Request('POST', '/login', remoteAddr: '203.0.113.7');
        $next = static fn (Request $r): Response => Response::text('ok');

        $this->assertSame(200, $middleware->handle($request, $next)->status);
        $this->assertSame(200, $middleware->handle($request, $next)->status);

        $blocked = $middleware->handle($request, $next);
        $this->assertSame(429, $blocked->status);
        $this->assertNotNull($blocked->headers['Retry-After'] ?? null);
    }

    public function testRateLimitKeysPerIp(): void
    {
        $store = new InMemoryRateLimitStore();
        $middleware = RateLimit::perMinute(1, $store);
        $next = static fn (Request $r): Response => Response::text('ok');

        $this->assertSame(200, $middleware->handle(new Request('POST', '/login', remoteAddr: '10.0.0.1'), $next)->status);
        $this->assertSame(200, $middleware->handle(new Request('POST', '/login', remoteAddr: '10.0.0.2'), $next)->status);
    }

    public function testSecurityHeadersStampHardeningDefaults(): void
    {
        $middleware = new SecurityHeaders();
        $response = $middleware->handle(
            new Request('GET', '/'),
            static fn (Request $r): Response => Response::html('<p>hi</p>')
        );

        $this->assertStringContainsString("default-src 'self'", $response->headers['Content-Security-Policy'] ?? '');
        $this->assertSame('DENY', $response->headers['X-Frame-Options'] ?? null);
        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options'] ?? null);
    }
}
