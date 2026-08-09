# lombokclarion/http

**Immutable Request/Response, middleware contract, per-request context, and multi-tenancy.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/http
```

## Namespace

```php
LombokClarion\Http
```

## What's Inside

| Class | Role |
|-------|------|
| `Request` | Immutable HTTP request (method, path, headers, body, query, files) |
| `Response` | Response value object (status, headers, body) |
| `Middleware` | Contract: `handle(Request, callable $next): Response` |
| `RequestContext` | Per-request mutable bag (authenticated user, tenant, locale) |
| `UploadedFile` | Uploaded file VO (tmp path, client name, mime, size) |
| `RendersResponse` | Trait with response helpers (`json()`, `html()`, `redirect()`) |
| `RuntimeAdapter` | Interface: translates runtime env → `Request` / emits `Response` |
| `Tenant` | Tenant value object (id + database key) |
| `TenantResolver` | Interface for resolving tenant from request |
| `HeaderTenantResolver` | Resolves tenant from `X-Tenant-ID` header |
| `ResolveTenant` | Middleware: binds tenant into `RequestContext` |
| `TenantAwareConnection` | DB-per-tenant via `ConnectionManager` template |
| `ErrorHandler` | Catches exceptions → rendered error responses (debug/production) |
| `ErrorPageRenderer` | Interface for rendering error pages |
| `PlainErrorPageRenderer` | Dependency-free plain HTML fallback renderer |
| `ExceptionReporter` | Interface for external error reporting (Sentry, etc.) |

## Usage

```php
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Http\Middleware;

// Reading a request
$request = Request::capture(); // from PHP superglobals
$name = $request->input('name');
$file = $request->file('avatar');

// Building a response
$response = Response::json(['ok' => true], 201);
$response = Response::html('<h1>Hello</h1>');
$response = Response::redirect('/dashboard');

// Middleware
class LogRequest implements Middleware {
    public function handle(Request $request, callable $next): Response {
        // before
        $response = $next($request);
        // after
        return $response;
    }
}
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
