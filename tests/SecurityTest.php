<?php

declare(strict_types=1);

use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Security\AesGcmEncrypter;
use LombokClarion\Security\Argon2idPasswordHasher;
use LombokClarion\Security\CsrfTokenManager;
use LombokClarion\Security\Encrypted;
use LombokClarion\Security\Exceptions\SecurityException;
use LombokClarion\Security\Exceptions\ValidationException;
use LombokClarion\Security\FormRequest;
use LombokClarion\Security\InMemoryRateLimitStore;
use LombokClarion\Security\RateLimit;
use LombokClarion\Security\SecurityConfig;
use LombokClarion\Security\SecurityHeaders;
use LombokClarion\Security\ValidateCsrf;

test('argon2id hasher hashes and verifies correctly', function () {
    $hasher = new Argon2idPasswordHasher(new SecurityConfig());
    $hash = $hasher->hash('correct horse battery staple');
    assertTrue($hasher->verify('correct horse battery staple', $hash));
    assertTrue(!$hasher->verify('wrong password', $hash));
});

test('security config rejects weak argon2 memory cost', function () {
    assertThrows(SecurityException::class, fn () => new SecurityConfig(argon2Memory: 1024));
});

test('csrf token manager validates only correctly signed tokens', function () {
    $tokens = new CsrfTokenManager('super-secret');
    $token = $tokens->generate();
    assertTrue($tokens->isValid($token));
    assertTrue(!$tokens->isValid('forged.value'));
    assertTrue(!$tokens->isValid(''));
});

test('ValidateCsrf blocks POST without a matching token', function () {
    $mw = new ValidateCsrf(new CsrfTokenManager('secret'));
    $request = new Request('POST', '/x', body: [], cookies: []);
    $response = $mw->handle($request, fn ($r) => Response::text('ok'));
    assertSame(419, $response->status);
});

test('ValidateCsrf allows POST with a matching, validly-signed token', function () {
    $tokens = new CsrfTokenManager('secret');
    $token = $tokens->generate();
    $mw = new ValidateCsrf($tokens);
    $request = new Request('POST', '/x', body: ['_csrf' => $token], cookies: ['csrf_token' => $token]);
    $response = $mw->handle($request, fn ($r) => Response::text('ok'));
    assertSame(200, $response->status);
});

test('ValidateCsrf allows GET requests through unconditionally', function () {
    $mw = new ValidateCsrf(new CsrfTokenManager('secret'));
    $request = new Request('GET', '/x');
    $response = $mw->handle($request, fn ($r) => Response::text('ok'));
    assertSame(200, $response->status);
});

test('RateLimit blocks after the configured max within the window', function () {
    $store = new InMemoryRateLimitStore();
    $mw = RateLimit::perMinute(2, $store);
    $request = new Request('GET', '/x');
    $next = fn ($r) => Response::text('ok');

    assertSame(200, $mw->handle($request, $next)->status);
    assertSame(200, $mw->handle($request, $next)->status);
    assertSame(429, $mw->handle($request, $next)->status);
});

test('SecurityHeaders adds default headers to the response', function () {
    $mw = new SecurityHeaders();
    $response = $mw->handle(new Request('GET', '/x'), fn ($r) => Response::text('ok'));
    assertSame('DENY', $response->headers['X-Frame-Options']);
    assertSame('nosniff', $response->headers['X-Content-Type-Options']);
});

test('Encrypted round-trips a value through AES-GCM', function () {
    $encrypter = new AesGcmEncrypter(random_bytes(32));
    $encrypted = Encrypted::fromPlaintext('1234-5678-9999', $encrypter);
    assertSame('1234-5678-9999', $encrypted->reveal($encrypter));
    assertTrue(!str_contains($encrypted->ciphertext(), '1234-5678-9999'));
});

test('AesGcmEncrypter rejects a key of the wrong length', function () {
    assertThrows(SecurityException::class, fn () => new AesGcmEncrypter('too-short'));
});

test('AesGcmEncrypter fails to decrypt tampered ciphertext', function () {
    $encrypter = new AesGcmEncrypter(random_bytes(32));
    $ciphertext = $encrypter->encrypt('secret');
    $tampered = substr($ciphertext, 0, -4) . 'AAAA';
    assertThrows(SecurityException::class, fn () => $encrypter->decrypt($tampered));
});

final class Test_CreateAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'age' => ['required', 'int'],
        ];
    }
}

test('FormRequest::validated returns only declared fields (mass assignment impossible)', function () {
    $request = new Request('POST', '/x', body: [
        'email' => 'a@example.com',
        'age' => '30',
        'is_admin' => '1', // NOT declared in rules() — must be dropped
    ]);
    $data = (new Test_CreateAccountRequest())->validated($request);
    assertSame(['email' => 'a@example.com', 'age' => '30'], $data);
    assertTrue(!array_key_exists('is_admin', $data));
});

test('FormRequest::validated throws on invalid input', function () {
    $request = new Request('POST', '/x', body: ['email' => 'not-an-email', 'age' => '30']);
    assertThrows(ValidationException::class, fn () => (new Test_CreateAccountRequest())->validated($request));
});
