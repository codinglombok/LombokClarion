# lombokclarion/auth

**Stateless HMAC token auth, RBAC, Gate/Policy authorization.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/auth
```

## Namespace

```php
LombokClarion\Auth
```

## What's Inside

| Class | Role |
|-------|------|
| `AuthManager` | Orchestrates login/logout/check; binds user into `RequestContext` |
| `TokenIssuer` | Creates and verifies HMAC-signed tokens |
| `TokenStore` | Interface for token persistence |
| `InMemoryTokenStore` | Testing token store |
| `UserProvider` | Interface for user lookup by ID or credentials |
| `Authenticatable` | Interface users must implement (getId/getPassword) |
| `Authenticate` | Middleware: require valid token → 401 |
| `Authorize` | Middleware: require ability → 403 |
| `Gate` | Ability definitions + authorization checks |
| `Policy` | Policy interface (one method per ability) |
| `RoleRepository` | Interface for role lookup |
| `DatabaseRoleRepository` | Database-backed RBAC role repository |

## Usage

```php
// Login (in controller)
$token = $authManager->login($request); // validates credentials, issues token
return Response::json(['token' => $token]);

// Protected route
$router->get('/me', [ProfileController::class, 'show'], [
    Authenticate::class, // 401 if no valid token
]);

// Authorization
$gate->define('widget.delete', WidgetPolicy::class);

// In controller:
$gate->authorize('widget.delete', $widget, $user); // throws 403

// Role-based
$gate->forUser($user)->can('admin.panel'); // bool
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
