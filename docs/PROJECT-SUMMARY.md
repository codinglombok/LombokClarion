# LombokClarion — Full Project Summary

> Status as of this document: **377 tests / 0 failures**, all quality gates green —
> including PHPStan and a real `composer install`. Stage 9 (auth + RBAC): AUDIT-TRAIL
> #34–37. Stage 10 (multi-DB ConnectionManager, read/write split, grammar seam):
> #38–39, compiled-boot verified. Stage 11 (laravel-flavor preset, compiled routes,
> pagination, typed params): zero new findings — the two structural hazards were
> designed out, with a live-server smoke through the compiled route index.

## What this is

LombokClarion is a PHP framework (PHP 8.3+, `strict_types` everywhere) built from
scratch on the opposite philosophy of
Laravel: **explicit over magic**. No facades in core (opt-in package available), no auto-discovery, no
ActiveRecord in core, a domain layer with zero framework imports, and an
edge/serverless-first design with a cold-start budget enforced at build time.

Structure: **Composer monorepo, 15 packages** + an end-to-end example app
(Widget feature: JSON API + HTML starter-kit pages + charts dashboard).

## Completion checklist vs. the design spec

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
- [x] 14 monorepo packages (the 12 required by §4 + `i18n` and `phpstan-rules` added
  in Stages 8/8b), each with a valid PSR-4 `composer.json`

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
- [x] Views use the library's ACTUAL vocabulary (`.btn/.card/.navbar/.table`, `--lc-*` tokens, `data-style`) — note: the original spec guessed `lc-*`/`data-variant`/`data-elevation`, which don't exist in the library
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
- `tests/` — 27 test files (377 tests) + `harness.php` (standalone runner) + `run-all.php` + `fixtures/views`
- `examples/` — 4 runnable single-file demos (micro HTTP, CommandBus, QueryBuilder+EagerLoader, Queue→Worker), all with verified output; `examples/README.md` lists expected output per file
- The FULL application example = `app/` + `bootstrap/` (Widget: JSON API, HTML pages, charts dashboard)

## Known remaining gaps (honest)
- [x] ~~PHPStan extension~~ → **`packages/phpstan-rules`** ships `NoRawSqlValuesRule` and
  `DomainBoundaryRule` with `extension.neon` + `type: phpstan-extension` composer.json.
  **Now actually executed** (PHPStan 2.2.5, `phpstan.neon` at the repo root, wired into
  CI): `[OK] No errors` across `app/`, `packages/`, `bootstrap/`. Running it for the first
  time is what surfaced the audit blind spot recorded as bug #28 — the rules had only ever
  been lint-checked, and a rule that has never executed has an unknown status, not a
  passing one.
- [x] ~~Least-privilege DB roles~~ → `deploy/db-roles.sql`
- [x] ~~A real Composer install~~ → **done, and it never needed Packagist.** Every
  dependency is a `path` repository, so `composer install` resolves the whole graph
  offline. The suite runs green (144/144) against Composer's own autoloader with
  `autoload.php` moved aside, and CI now does exactly that on every push. Doing so
  immediately exposed bug #31: `active-record`, `facades` and `i18n` were missing from
  `composer.json` and existed only in the sandbox shim's map.

## Remaining follow-up (not blocking)
- [x] ~~`phpstan.neon` runs at **level 0**~~ → **now level 5**, with
  `treatPhpDocTypesAsCertain: false`. The pre-existing backlog was worked off:
  real defects fixed (ConfigCompiler match default, the `ViewCompiler` `@extends`
  docblock parse error, a variadic `@param`, a redundant loop condition, `self::`
  on a private `Model` method, a write-only property), and the three PHPStan
  stub/inference limitations that remain (`Model::find` static covariance, the
  `WorkCommand` daemon loop, `Container`'s defensive `ReflectionClass` catch) each
  carry a *sited* `@phpstan-ignore` with its reason. CI inherits the level (it
  runs `analyse -c phpstan.neon`, no `--level`). Level 6 is left as a separate
  annotation project.
- [ ] `packages/persistence/src/QueryBuilder.php` is exempt from `rawSqlValue` in both
  engines (reasoning is inline in `phpstan.neon`). Neither constant folding nor token
  inspection can see through `implode()`/`sprintf()` over arrays. The exemption is paid
  for by `PersistenceTest`, not waved away.

## §i18n Internationalization (`lombokclarion/i18n`) — implemented
- [x] `Translator`: catalogs are plain PHP files registered by hand (manifest, never scanned); `:param` interpolation; `choice()` pluralization (`one|many`, `:count` auto-available); fallback locale; missing keys returned as-is AND recorded — `assertNoMissingKeys()` lets CI prove catalogs are complete
- [x] `DetectLocale` per-route middleware: `?lang=` → `Accept-Language` (incl. primary-tag match `en-US`→`en`) → default, restricted to an explicit allow-list; chosen locale stored in `RequestContext` (never a hidden global)
- [x] `resources/lang/` ships **24 locale catalogs** (en, id, es, fr, de, pt, ru, zh, ja, ko, ar, hi, bn, ur, tr, vi, th, it, nl, pl, ms, sw, fa, uk — covering the world's most-spoken languages) + a looped parity test proving every catalog covers every `en` key — 7 tests

## §Stage 9 Auth + RBAC (`lombokclarion/auth`) — implemented
- [x] `Authenticatable` (id + password hash only) + `AuthManager` (attempt/login/logout) on the existing `PasswordHasher` + `RequestContext`; the current user lives in RequestContext, there is no static `Auth::user()`
- [x] Stateless HMAC session tokens (`TokenIssuer`, base64url id so emails-as-ids round-trip); `TokenStore` seam for opaque/DB tokens with `sha256(token)` storage; `attempt()` hashes on the not-found path to close the user-enumeration timing oracle
- [x] `Authenticate` (per-route, 401, cookie + Bearer) binds the user into RequestContext; `Authorize::role()/permission()` instance middleware (401 vs 403 distinguished; body discloses nothing)
- [x] RBAC migrations (`users`, `roles`, `permissions`, `role_user`, `permission_role`) in the hand-written manifest with composite PKs; `Gate` with hand-registered policies (`$gate->define(...)`), throws on unknown ability rather than denying — no attribute scanning
- [x] `audit:security` extension: mutating routes lacking Authenticate reported at warning level, `--public=METHOD:/path` to declare intentional exceptions, `--strict` to fail on them; login route combines RateLimit(5/min) + CSRF in the example app
- [x] 42 auth tests + end-to-end Kernel smoke (login, cookie/Bearer, tamper, expiry, stateless logout, CSRF-less → 419)

## §Stage 10 Multi-database `ConnectionManager` — implemented
- [x] `ConnectionManager` with typed `ConnectionConfig` entries (`dsn` / `provided` for compiled-boot `$externallyProvided` / `{database}` `template`); lazy PDO creation, per-name (and per-database) caching, unknown names are hard errors
- [x] Read/write split: `read()`/`write()` distinct PDOs when configured; without a split, `read()` returns the *identical* instance — and no sticky-connection magic, read-your-own-write asks for `write()` explicitly
- [x] `Grammar` seam (`AnsiGrammar` `"`, `MySqlGrammar` `` ` ``) through QueryBuilder AND EagerLoader — quoting only, `Identifier::validate()` unchanged in both; `GrammarFactory` throws on unknown drivers; `QueryBuilder::toSql()` for serverless SQL assertions; `ConnectionManager::table()` pairs PDO+grammar so they can't be mismatched
- [x] `TenantAwareConnection` generalized: delegates through `ConnectionManager::forDatabase()` (validated `{database}` substitution — `;`/`=`/`..` rejected); Stage 9 static signature kept
- [x] `migrate --connection=NAME` (unknown connection/argument = exit 1, nothing migrated); per-connection `migrations` table; always the write side
- [x] 28 new tests incl. two isolated SQLite connections via the flag, mysql-grammar SQL generation with no server, and compiled-container tenant resolution

## §Stage 11 `laravel-flavor` preset + compiled routes — implemented
- [x] `LaravelFlavor::enable($container)` — the whole magic surface in ONE greppable line: `Facade::setContainer` (Bus/Hash/Event + new Auth/DB facades), `Model::setConnection` via the Stage 10 manager's **write** side, returns `[SecurityHeaders]` as recommended global middleware (returned, never installed behind your back); `disable()` is a true undo; `forbidden-layers: ["app/Domain"]` applies and the suite proves the checker's teeth on this package
- [x] Route compilation: `optimize` emits `storage/routes.compiled.php` — a match index only (regex table + O(1) exact-path map, pointing into the live table by index; instance middleware is never serialized); order-sensitive fingerprint makes a stale index a loud boot error naming the fix; `Route` regex compile is now lazy so the compiled path builds zero regexes per request
- [x] Benchmark as a test: worst-case (last dynamic route of 500) ≈0.036 ms mean vs the 0.5 ms budget; static paths ≈0.0001 ms
- [x] `QueryBuilder::paginate(page, perPage)` → `['data' => rows, 'meta' => page/per_page/total/last_page/from/to]`; COUNT and page share wheres/joins; `page` clamps (URL-shaped input), `per_page <= 0` throws (code-shaped input)
- [x] Typed route params: `{id:int}` narrows the match (non-digits → 404, no "matched but invalid" state) AND casts before the controller; unknown types throw with the known list; vocabulary deliberately just `int`/`string`
- [x] 21 new tests; end-to-end smoke: `php -S` serving through the compiled index (200 on /widgets, 404 preserved)

## §Stage 12 validation + §Stage 13 storage/upload — implemented
- [x] Stage 12: `lombokclarion/validation` — typed rule objects (no pipe-string DSL), validate+canonicalize in one step, whitelist output, all-violations, `FormRequest<TDto>` explicit `map()` (zero reflection), `ValidationException` → Kernel 422 via the `RendersResponse` seam, messages in all 24 catalogs shipped inside the package (`Lang::catalog()`, #47)
- [x] Stage 13: `lombokclarion/storage` — `Storage` interface + `LocalStorage` rooted at `storage/app` (traversal = hard error at the boundary, generated names, caller-explicit validated extensions), `UploadedFile::moveTo()` provenance-aware single-shot move, `Rule::file(mimes)->maxBytes()` content-sniffed via finfo, FormRequest multipart merge (`UPLOAD_ERR_NO_FILE` = absent)

## Roadmap
Stages 1–13 of the design specification are implemented. Rationale for the
overall design is documented internally. Distribution is decided and
wired: the monorepo at `codinglombok/LombokClarion` is canonical, and each
package is mirrored read-only to `codinglombok/<pkg>` for Packagist under the
`lombokclarion/*` vendor — see `docs/PUBLISHING.md`, `bin/split-package.sh`, and
`.github/workflows/split.yml`.

## Quick usage

```bash
php tests/run-all.php                 # 377 tests
php bin/lombokclarion migrate         # or: migrate --connection=reporting
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
