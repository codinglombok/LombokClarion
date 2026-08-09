# lombokclarion/security

**Argon2id hashing, CSRF double-submit, rate limiting, AES-256-GCM encryption, security headers.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/security
```

## Namespace

```php
LombokClarion\Security
```

## What's Inside

| Class | Role |
|-------|------|
| `PasswordHasher` | Interface: `hash()`, `verify()`, `needsRehash()` |
| `Argon2idPasswordHasher` | Argon2id with OWASP-minimum cost validation at boot |
| `Encrypter` | Interface: `encrypt()`, `decrypt()` |
| `AesGcmEncrypter` | AES-256-GCM authenticated encryption |
| `Encrypted` | Typed wrapper for encrypted values in domain models |
| `CsrfTokenManager` | Stateless HMAC double-submit CSRF tokens |
| `ValidateCsrf` | Middleware: validates CSRF on mutating requests |
| `RateLimitStore` | Backend interface for rate-limit counters |
| `InMemoryRateLimitStore` | Per-process in-memory rate limiting |
| `RateLimit` | Middleware factory: `RateLimit::perMinute(n, $store)` |
| `SecurityHeaders` | Middleware: CSP, HSTS, X-Frame-Options, etc. |
| `SecurityConfig` | Security configuration value object |
| `FormRequest` | Secure form request (extends validation FormRequest) |

## Usage

```php
// Password hashing
$hasher = new Argon2idPasswordHasher();
$hash = $hasher->hash('secret');
$hasher->verify('secret', $hash); // true

// Encryption
$encrypter = new AesGcmEncrypter($appKey);
$cipher = $encrypter->encrypt('sensitive data');
$plain = $encrypter->decrypt($cipher);

// CSRF (in routes)
$router->post('/form', [Controller::class, 'submit'], [
    ValidateCsrf::class,
]);

// Rate limiting
$router->post('/login', [AuthController::class, 'login'], [
    RateLimit::perMinute(5, $rateLimitStore),
]);
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
