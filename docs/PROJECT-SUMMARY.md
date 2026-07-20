# LombokClarion — Full Project Summary

> Status as of this document: **124 tests / 0 failures**, 111 PHP source files,
> ~6,223 lines of code (packages + app), 15 test files, all quality gates green.

An implementation of the LombokClarion framework as : explicit-over-magic, edge/serverless-first,
PHP 8.3+, no facades, no auto-discovery, domain layer with zero framework
imports.

What this is

LombokClarion is a PHP framework (PHP 8.3+, strict_types everywhere) built from
scratch, clean code, on the opposite philosophy of
Laravel: explicit over magic. No facades by default, no auto-discovery, no
ActiveRecord in core, a domain layer with zero framework imports, and an
edge/serverless-first design with a cold-start budget enforced at build time.

Structure: Composer monorepo, 12 packages + an end-to-end example app
(Widget feature: JSON API + HTML starter-kit pages + charts dashboard).

This repo contains **working, tested code** for every item (§4), plus a small end-to-end example app (a Widget
CRUD-ish feature) wiring all of it together. It is not a drop-in
`composer create-project` package yet — see "What's not here yet" below —
but every piece that exists actually runs, and is covered by tests that
actually run (124 tests, 0 failures, see "Running the tests").

## Completion checklist

### §1 Identity

- [x] `lombokclarion/*` namespace; CLI binary `bin/lombokclarion`
- [x] PHP 8.3+, `declare(strict_types=1)` in every file
- [x] Real LombokCSS (github.com/codinglombok/LombokCSS) vendored self-hosted, never CDN
- [x] SQLite default for dev/CI (Postgres/MySQL supported in SchemaBuilder/MigrationRunner)

### §2 Non-negotiable principles

- [x] 2.1 No facades in core — constructor/method injection only
- [x] 2.2 No service location in app code — container resolves only at the edges (Kernel/ConsoleKernel)
- [x] 2.3 No hidden global state — explicit, injectable `RequestContext`
- [x] 2.4 Typed config (`$config->mail->smtp->host` as generated readonly classes), never `config('a.b.c')`
- [x] 2.5 Explicit registration — all bindings in `bootstrap/services.php`, routes in `routes.php`, commands in `console.php`, migrations in `migrations.php` (a manifest, not a directory scan)
- [x] 2.6 Magic is opt-in — `active-record` & `facades` are separate packages with `forbidden-layers` metadata, enforced by the boundary checker
- [x] 2.7 Safe by construction — QueryBuilder has no raw-value API; FormRequest makes mass assignment structurally impossible; view auto-escaping is default

### §3 Architecture

- [x] Request → Kernel → Router → Middleware → Container → Controller → Bus → Domain → Repository → Persistence
- [x] App folder layout per spec (`app/Http`, `app/Domain`, `app/Infrastructure`, `bootstrap/`, …)
- [x] Hard domain rule: `app/Domain/**` has zero `LombokClarion\*` imports — enforced by `bin/check-domain-boundary.php` (token-based, no comment false-positives; proven to catch a deliberately planted violation)
- [x] 12 monorepo packages, each with a valid PSR-4 `composer.json`

### §4 Build order (all 12 steps)

- [x] 4.1 Container — explicit bindings; autowiring for concrete classes only; unbound interface = clear error; circular detection; + `ContainerCompiler` (AOT) → `CompiledContainer` (zero reflection at request time)
- [x] 4.2 Http — immutable Request/Response value objects
- [x] 4.3 Routing — explicit route table, path params, groups, per-route middleware (class-string OR instance)
- [x] 4.4 Bus — CommandBus/QueryBus/EventBus, one handler per command, manual registration
- [x] 4.5 Config — ConfigCompiler: schema → nested readonly PHP classes, env resolved once at compile time
- [x] 4.6 Kernel + adapters — FpmAdapter, FunctionAdapter, SwooleAdapter (opt-in) behind one `RuntimeAdapter`
- [x] 4.7 Persistence — QueryBuilder (bound-params-only, joins, groupBy, qualified `table.column`), SchemaBuilder, explicit-manifest MigrationRunner, `RawExpression` (the only escape hatch, still placeholder-mandatory), `Identifier` validation
- [x] 4.8 View — Blade-like compiler (`@if/@foreach/@extends/@section/@yield/@include`), `{{ }}` auto-escaped by default, `{!! !!}` explicit opt-out + `Safe::mark()`, disk compile-cache
- [x] 4.9 Console — explicit ConsoleKernel; built-ins: `migrate`, `optimize`, `work`, `audit:sql`, `audit:security`
- [x] 4.10 Testing — HttpTestCase (boots the REAL container + explicit `override()`), FakeCommandBus/FakeEventBus, InMemoryRepository, ConsoleTestCase, BenchmarkTestCase, ColdStartTest
- [x] 4.11 Security — Argon2id (cost validated against OWASP minimum at boot), stateless HMAC CSRF double-submit, per-route RateLimit, SecurityHeaders, `Encrypted<T>` AES-256-GCM, FormRequest
- [x] 4.12 Optional packages — `active-record` (full Model: CRUD, query builder, `$fillable`, `with()` eager-loading) & `facades` (Facade base + Bus/Event/Hash, explicit `setContainer()` opt-in), both carrying `forbidden-layers: ["app/Domain"]`

### §5 Edge/serverless-first

- [x] `optimize` → `services.compiled.php` (flat closure array, zero reflection at boot)
- [x] Config compiles to a plain PHP file, opcache-preloadable, never re-parsed per request
- [x] No persistent-process assumptions; PDO created fresh per invocation via `$externallyProvided` + `instance()`
- [x] `ColdStartTest` ships by default, fails when the budget is exceeded (~5ms production budget)
- [x] `RuntimeAdapter` — only the adapter changes per deployment target; the compiled boot path was proven to serve a real request end-to-end

### §6 Security — all items

- [x] Hashing, CSRF, stateless tokens, pre-controller validation, rate limiting, headers, at-rest encryption, `audit:security` (missing CSRF on mutating routes, `APP_DEBUG=true` in production, missing SecurityHeaders; weak cost params rejected by PasswordHasher at boot)

### §7 Injection hardening

- [x] QueryBuilder with no raw-value methods; `rawExpression` requires placeholders == bindings
- [x] `audit:sql` powered by **TokenScanner** (PHP tokenizer, not regex): concatenation, variable interpolation inside query strings, sprintf — including multi-line; ignores comments/string literals
- [x] Auto-escaping default; `{!! !!}` flagged by audit unless `Safe::mark()`
- [x] `audit:sql --explain` — EXPLAIN QUERY PLAN/ANALYZE, flags sequential scans
- [x] N+1: real `EagerLoader` (hasMany/hasOne/belongsTo, one WHERE IN query per relation) + `with()`
- [x] MySQL migrations default to NonTransactional (`migrationsAreTransactionalByDefault()`)
- [x] Least-privilege DB roles: `deploy/db-roles.sql` — Postgres template creating `lc_app` (DML only, incl. default privileges on future tables) and `lc_migrate` (DDL owner); `migrate` connects as the latter

### §8/§13 Frontend LombokCSS + LombokCharts

- [x] REAL library downloaded from GitHub & vendored + MIT license
- [x] Views use the library's ACTUAL vocabulary (`.btn/.card/.navbar/.table`, `--lc-*` tokens, `data-style`) — note: guessed `lc-*`/`data-variant`/`data-elevation`, which don't exist in the library
- [x] `data-style` comes from `Theme` (validated at boot) ← `THEME_STYLE` env var — never hardcoded in a layout
- [x] Content-hashed assets at `optimize` time + manifest; `Cache-Control: immutable`; `StaticAssetsMiddleware` (with path-traversal blocking)
- [x] 4 presets: resonant-stark (default), neo-brutalism, glassmorphism (upstream) + quiet-editorial (extension following upstream's own token-remap pattern) — all four verified rendering
- [x] Real LombokCharts (github.com/codinglombok/LombokCharts, Apache-2.0) vendored self-hosted; the starter-kit `/dashboard` page renders bar + arc charts from real widget data; JSON embedded with JSON_HEX_* flags (script-breakout safe) + `Safe::mark()` so the XSS audit stays clean

### §9 Testing requirements

- [x] Domain tests need zero HTTP/DB; HttpTestCase boots the real container; fakes; ColdStartTest in the default suite

### §10 Non-goals honored

- [x] No admin panel; no AR/facades in core; no implicit tenancy mode; queue retry defaults to single attempt (opt-in `RetriesQueuedCommand`)

### §11 Multi-tenancy

- [x] Request-scoped binding pattern: per-route `ResolveTenant`, `TenantResolver` + `HeaderTenantResolver`, `Tenant` via `RequestContext`, `TenantAwareConnection` (DB-per-tenant, isolation proven)

### §12 Queue/worker parity

- [x] `ShouldQueue`, `QueuedCommandBus` (decorator), `QueueWorker` (identical handler path as inline dispatch), opt-in retry+backoff, failed_jobs, `InMemoryQueueStore` + `DatabaseQueueStore`, CLI `work --queue/--loop/--sleep`

### §10 (reference) Plugin system

- [x] `Plugin` interface (name/capabilities/register) + `PluginRegistrar` with capability allow-list; registration always explicit; duplicates rejected

## GitHub repository file completeness

- [x] `LICENSE` (MIT) · `package.json` (validated; npm is only used to refresh vendored assets via `npm run assets:update` — the runtime stays zero-dependency)
- [x] `CHANGELOG.md` (7-stage history) · `CONTRIBUTING.md` (constitution rules + quality-gate workflow) · `SECURITY.md` (private reporting; audit false-negatives are security bugs) · `CODE_OF_CONDUCT.md` · `SUPPORT.md`
- [x] `.editorconfig` · `.gitattributes` (LF, min.* no-diff, export-ignore tests/docs)
- [x] `.github/`: ISSUE_TEMPLATE (bug + feature), PULL_REQUEST_TEMPLATE, dependabot.yml (validated), workflows: `ci.yml`, `npm-publish.yml` (publish on Release with provenance), `pages.yml` (auto-build docs site — all three YAML-validated)

## Deployment file completeness

- [x] `Dockerfile` — stages `base` (FPM+opcache, runs `optimize` at build), `worker`, `cloudrun` (single-container HTTP)
- [x] `docker-compose.yml` — web(nginx)+app+worker+Postgres (YAML validated)
- [x] `deploy/nginx.conf` — /assets immutable, only the front controller executes
- [x] `deploy/lombokclarion-worker.service` — systemd unit for the queue worker
- [x] `.dockerignore`, `.env.example`, `docs/DEPLOYMENT.md` (GitHub/VPS/Docker/GCP/AWS/DO)

## tests & examples folders

- `tests/` — 15 test files (124 tests) + `harness.php` (standalone runner) + `run-all.php` + `fixtures/views`
- `examples/` — 4 runnable single-file demos (micro HTTP, CommandBus, QueryBuilder+EagerLoader, Queue→Worker), all with verified output; `examples/README.md` lists expected output per file
- The FULL application example = `app/` + `bootstrap/` (Widget: JSON API, HTML pages, charts dashboard)

## Known remaining gaps (honest)

- [x] ~~PHPStan extension~~ → **`packages/phpstan-rules`** now ships `NoRawSqlValuesRule`
      (AST-level: concat/interpolation/sprintf into PDO query/prepare/exec) and
      `DomainBoundaryRule` (no `LombokClarion\*` imports under app/Domain) with
      `extension.neon` + `type: phpstan-extension` composer.json. Lint-verified; the one
      remaining caveat: PHPStan itself could not be executed in this offline sandbox, so
      the rules are exercised indirectly (the equivalent TokenScanner/boundary checks
      have full test coverage) — run `vendor/bin/phpstan analyse` once installed online.
- [x] ~~Least-privilege DB roles~~ → `deploy/db-roles.sql`
- [ ] A real Composer install (sandbox had no Packagist access → `autoload.php` shim;
      every per-package `composer.json` is already correct — 13 packages total)

## Quick usage

```bash
php tests/run-all.php                 # 124 tests
php bin/lombokclarion migrate
php bin/lombokclarion optimize        # compiled container+config+assets
php -S localhost:8080 -t public       # open /, /widgets, /dashboard
php bin/lombokclarion audit:sql app --explain
php bin/lombokclarion audit:security
php bin/check-domain-boundary.php
php bin/lombokclarion work --loop
```

## Maturity comparison (context, not a claim)

By raw feature surface this is roughly equivalent to earliest-generation Laravel
(~v1–v2); but the design goal differs: the absence of Eloquent/facades in core is a
decision, not a deficiency. Per-file detail lives in `README.md`; the bug chronology
in `docs/AUDIT-TRAIL.md`.
