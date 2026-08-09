# LombokClarion — Architecture Map & Development Plan

> **Living document** — update this every stage. Created Stage 17 Reconcile.  
> Use alongside memory when resuming in a new session.

---

## 1. Project Identity

| Field | Value |
|---|---|
| **Name** | LombokClarion |
| **Vendor** | `lombokclarion/*` (Packagist) / `codinglombok/*` (GitHub) |
| **PHP** | `>=8.3`, `strict_types` everywhere |
| **License** | Apache-2.0 |
| **Monorepo** | 20 packages + example app |
| **Tests** | 377 tests / 0 failures (custom harness, no PHPUnit dependency) |
| **DB default** | SQLite (dev/CI); MySQL/Postgres supported |

---

## 2. Package Registry (20 packages)

### 2.1 Core Runtime (16 packages → `lombokclarion/framework` metapackage)

| # | Package | Namespace | Files | Status | Description |
|---|---------|-----------|-------|--------|-------------|
| 1 | `container` | `LombokClarion\Container` | 9 | ✅ COMPLETE | Explicit DI + AOT `ContainerCompiler` → `CompiledContainer` |
| 2 | `config` | `LombokClarion\Config` | 2 | ✅ COMPLETE | Schema → readonly PHP classes, env resolved once at compile |
| 3 | `http` | `LombokClarion\Http` | 16 | ✅ COMPLETE | Immutable Request/Response, Middleware, Tenancy, ErrorHandler |
| 4 | `routing` | `LombokClarion\Routing` | 8 | ✅ COMPLETE | Router, Route, Kernel, 3 adapters (FPM/Function/Swoole), CompiledRouteMatcher |
| 5 | `bus` | `LombokClarion\Bus` | 16 | ✅ COMPLETE | Command/Query/Event Bus + Queue (Database/InMemory), Worker, retry |
| 6 | `persistence` | `LombokClarion\Persistence` | 20 | ✅ COMPLETE | QueryBuilder, SchemaBuilder, Migrations, Seeding, ConnectionManager, EagerLoader |
| 7 | `validation` | `LombokClarion\Validation` | 6 | ✅ COMPLETE | Rule builder, Validator, FormRequest, 24 language files |
| 8 | `security` | `LombokClarion\Security` | 15 | ✅ COMPLETE | Argon2id, CSRF, RateLimit, SecurityHeaders, AES-GCM Encryption |
| 9 | `auth` | `LombokClarion\Auth` | 13 | ✅ COMPLETE | HMAC TokenIssuer, RBAC, Gate/Policy, Authenticate/Authorize middleware |
| 10 | `view` | `LombokClarion\View` | 9 | ✅ COMPLETE | Blade-like compiler, auto-escape, Theme, AssetManifest, AssetPublisher |
| 11 | `console` | `LombokClarion\Console` | 14 | ✅ COMPLETE | ConsoleKernel + 12 built-in commands (migrate/seed/optimize/audit/work) |
| 12 | `log` | `LombokClarion\Log` | 13 | ✅ COMPLETE | Structured logging: channels, handlers, formatters, redaction |
| 13 | `i18n` | `LombokClarion\I18n` | 2 | ✅ COMPLETE | Translator + DetectLocale middleware |
| 14 | `storage` | `LombokClarion\Storage` | 2 | ✅ COMPLETE | Local filesystem storage with path-traversal blocking |
| 15 | `active-record` | `LombokClarion\ActiveRecord` | 3 | ✅ COMPLETE | Opt-in Model with CRUD, query builder, eager-loading |
| 16 | `facades` | `LombokClarion\Facades` | 4 | ✅ COMPLETE | Opt-in Facade base + Bus/Event/Hash |

### 2.2 Companion Packages

| # | Package | Namespace | Files | Status | Description |
|---|---------|-----------|-------|--------|-------------|
| 17 | `laravel-flavor` | `LombokClarion\LaravelFlavor` | 3 | ✅ COMPLETE | Auth/DB preset, `config()` helper, `env()` helper |
| 18 | `testing` | `LombokClarion\Testing` | 7 | ✅ COMPLETE | HttpTestCase, FakeCommandBus, FakeEventBus, ColdStartTest |
| 19 | `phpstan-rules` | `LombokClarion\PHPStanRules` | 2 | ✅ COMPLETE | DomainBoundaryRule, NoRawSqlValuesRule |
| 20 | `framework` | *(metapackage)* | 0 | ✅ COMPLETE | Requires all 16 runtime packages |

---

## 3. Application Layer (`app/`)

```
app/
├── Console/
│   ├── Commands/                      # (empty — user commands go here)
│   └── UserCreateCommand.php          ✅
├── Domain/
│   ├── Account/
│   │   └── User.php                   ✅ (value object, zero framework imports)
│   └── Widget/
│       ├── Widget.php                 ✅
│       ├── WidgetRepositoryInterface.php ✅
│       ├── CreateWidget.php           ✅ (command)
│       ├── CreateWidgetHandler.php    ✅
│       ├── ListWidgets.php            ✅ (query)
│       └── ListWidgetsHandler.php     ✅
├── Http/
│   ├── Controllers/
│   │   ├── AuthController.php         ✅
│   │   ├── PageController.php         ✅
│   │   └── WidgetController.php       ✅
│   └── Requests/
│       └── CreateWidgetRequest.php    ✅
└── Infrastructure/
    ├── Auth/
    │   ├── AuthenticatableUser.php    ✅
    │   ├── DatabaseUserProvider.php   ✅
    │   └── WidgetPolicy.php          ✅
    ├── Persistence/
    │   ├── Migrations/
    │   │   ├── CreateAuthTables.php   ✅
    │   │   └── CreateWidgetsTable.php ✅
    │   ├── Seeders/
    │   │   └── DemoWidgetsSeeder.php  ✅
    │   └── SqlWidgetRepository.php   ✅
    └── ServiceFactories.php          ✅ (275 lines, all factory methods)
```

---

## 4. Bootstrap Layer

| File | Purpose | Status |
|------|---------|--------|
| `bootstrap/services.php` | Container wiring (all bindings) | ✅ COMPLETE |
| `bootstrap/routes.php` | Route registration | ✅ COMPLETE |
| `bootstrap/console.php` | Console command registration | ✅ COMPLETE |
| `bootstrap/externals.php` | PDO + ConnectionManager (non-serializable) | ✅ COMPLETE |
| `bootstrap/migrations.php` | Migration manifest | ✅ COMPLETE |
| `bootstrap/seeders.php` | Seeder manifest | ✅ COMPLETE |

---

## 5. Request Lifecycle

```
Client Request
    │
    ▼
┌─────────────────────────────────────┐
│ RuntimeAdapter (FPM/Function/Swoole)│
│   ↓ creates Request from superglobals│
└──────────────┬──────────────────────┘
               ▼
┌─────────────────────────────────────┐
│ Kernel::handle(Request)             │
│   1. Bind RequestContext (per-req)  │
│   2. Match route (compiled or live) │
│   3. Build middleware pipeline      │
│      [SecurityHeaders]              │
│      [ValidateCsrf] (per-route)     │
│      [RateLimit] (per-route)        │
│      [Authenticate] (per-route)     │
│      [DetectLocale] (per-route)     │
│      [ResolveTenant] (per-route)    │
│   4. Resolve Controller from DI     │
│   5. Call controller action         │
│   6. ErrorHandler wraps all above   │
└──────────────┬──────────────────────┘
               ▼
┌─────────────────────────────────────┐
│ Controller                          │
│   ↓ dispatches to Bus               │
│   CommandBus / QueryBus / EventBus  │
│   ↓ handler resolves Repository     │
│   ↓ Repository uses QueryBuilder    │
│   ↓ QueryBuilder → PDO (bound params)│
│   ↓ returns Response                │
└─────────────────────────────────────┘
```

---

## 6. Build/Optimize Pipeline

```
bin/lombokclarion optimize
    │
    ├── ContainerCompiler → storage/services.compiled.php
    ├── ConfigCompiler    → storage/config.compiled.php
    ├── RouteCompiler     → storage/routes.compiled.php
    └── AssetPublisher    → public/assets/* (content-hashed)
                          → storage/assets.manifest.php
```

---

## 7. Capability Matrix

### HAS (backend-complete)

| Capability | Package(s) | Key Classes |
|-----------|------------|-------------|
| DI Container + AOT | `container` | Container, ContainerCompiler, CompiledContainer |
| HTTP layer | `http` | Request, Response, Middleware, UploadedFile |
| Routing + 3 adapters | `routing` | Router, Kernel, FpmAdapter, FunctionAdapter, SwooleAdapter |
| Persistence | `persistence` | QueryBuilder, SchemaBuilder, MigrationRunner, SeederRunner |
| ActiveRecord (opt-in) | `active-record` | Model, ModelQueryBuilder |
| Auth (HMAC + RBAC) | `auth` | TokenIssuer, AuthManager, Gate, Policy, Authenticate/Authorize |
| Validation + i18n | `validation`, `i18n` | Rule, Validator, FormRequest, Translator (24 langs) |
| Security | `security` | Argon2id, CsrfTokenManager, RateLimit, AesGcmEncrypter, SecurityHeaders |
| View engine | `view` | ViewCompiler, ViewEngine, Theme, AssetManifest, StaticAssetsMiddleware |
| Console (12 commands) | `console` | ConsoleKernel, migrate, seed, optimize, audit:sql, audit:security, work |
| Bus (CQRS + Events) | `bus` | CommandBus, QueryBus, EventBus + Queue/Worker |
| Logging | `log` | ChannelLogger, StreamHandler, JsonFormatter, Redactor |
| Storage | `storage` | LocalStorage (filesystem) |
| Docker + deploy | root | Dockerfile, docker-compose.yml, nginx.conf, worker.service |

### DOES NOT HAVE (out of scope)

| Capability | Notes |
|-----------|-------|
| Mail/SMTP | No mailer package |
| Cache | No cache package (rate-limit uses in-memory store) |
| Session | Stateless token auth only, no server-side sessions |
| Notification | No notification system |
| WebSocket/real-time | No broadcasting/WebSocket |
| Frontend build (Vite/Webpack) | CSS/JS vendored, no build pipeline |
| SPA integration (Inertia/Livewire) | Server-rendered views only |
| Cloud file upload (S3/GCS) | LocalStorage only |
| Scheduler/Cron | No scheduler package |
| Broadcasting | No broadcast system |

---

## 8. File Inventory per Package

### 8.1 `container` — LombokClarion\Container

```
src/
├── Binding.php                   — value object wrapping a DI binding
├── CompiledContainer.php         — AOT-compiled zero-reflection container
├── Container.php                 — runtime container with bind/singleton/instance
├── ContainerCompiler.php         — compiles Container → PHP file
├── ContainerInterface.php        — get()/has() interface (PSR-11-like)
├── Plugin.php                    — plugin contract (name/capabilities/register)
├── PluginRegistrar.php           — registers plugins with capability allow-list
└── Exceptions/
    ├── ContainerException.php    — base container error
    └── NotFoundException.php     — service not found
```

### 8.2 `config` — LombokClarion\Config

```
src/
├── ConfigCompiler.php            — schema → readonly PHP classes + env resolution
└── Exceptions/
    └── ConfigException.php       — config validation error
```

### 8.3 `http` — LombokClarion\Http

```
src/
├── Middleware.php                 — middleware interface (handle + next)
├── Request.php                   — immutable PSR-7-like request
├── RequestContext.php            — per-request mutable context (user, tenant, locale)
├── Response.php                  — response value object
├── RendersResponse.php           — trait for controller response helpers
├── RuntimeAdapter.php            — adapter interface for runtime environments
├── UploadedFile.php              — uploaded file value object
├── Tenant.php                    — tenant value object (id + database)
├── TenantResolver.php            — interface for resolving tenant from request
├── HeaderTenantResolver.php      — resolves tenant from X-Tenant-ID header
├── ResolveTenant.php             — middleware that binds tenant into context
├── TenantAwareConnection.php     — db-per-tenant connection from ConnectionManager
└── Errors/
    ├── ErrorHandler.php          — catches exceptions → rendered error responses
    ├── ErrorPageRenderer.php     — interface for rendering error pages
    ├── PlainErrorPageRenderer.php — dependency-free plain HTML error renderer
    └── ExceptionReporter.php     — interface for external error reporting
```

### 8.4 `routing` — LombokClarion\Routing

```
src/
├── Router.php                    — route registration + matching
├── Route.php                     — route value object (method, path, handler, middleware)
├── Kernel.php                    — request lifecycle orchestrator
├── RouteCompiler.php             — compiles routes → static PHP match index
├── CompiledRouteMatcher.php      — loads compiled index for O(1) matching
└── Adapters/
    ├── FpmAdapter.php            — PHP-FPM (traditional Apache/Nginx)
    ├── FunctionAdapter.php       — serverless/edge (Lambda, Cloudflare Workers)
    └── SwooleAdapter.php         — Swoole/OpenSwoole persistent worker
```

### 8.5 `bus` — LombokClarion\Bus

```
src/
├── CommandBus.php                — dispatches command → single handler
├── CommandHandler.php            — command handler interface
├── QueryBus.php                  — dispatches query → single handler
├── QueryHandler.php              — query handler interface
├── EventBus.php                  — dispatches event → multiple listeners
├── EventListener.php             — event listener interface
├── RetryPolicy.php               — retry configuration (maxAttempts, backoff)
├── RetriesQueuedCommand.php      — interface for commands with custom retry
├── Queue/
│   ├── ShouldQueue.php           — marker interface for queueable commands
│   ├── QueuedJob.php             — job wrapper (payload + metadata)
│   ├── QueueStore.php            — queue backend interface
│   ├── InMemoryQueueStore.php    — testing queue backend
│   ├── DatabaseQueueStore.php    — persistent queue (jobs/failed_jobs tables)
│   ├── QueuedCommandBus.php      — decorator: wraps CommandBus for async dispatch
│   └── QueueWorker.php           — pulls jobs, dispatches through CommandBus
└── Exceptions/
    └── HandlerNotFoundException.php — no handler registered for message
```

### 8.6 `persistence` — LombokClarion\Persistence

```
src/
├── QueryBuilder.php              — bound-params-only SQL builder (472 lines)
├── SchemaBuilder.php             — DDL builder (create/alter/drop table)
├── Grammar.php                   — SQL dialect interface
├── AnsiGrammar.php               — ANSI SQL (SQLite)
├── MySqlGrammar.php              — MySQL dialect
├── GrammarFactory.php            — resolves grammar from driver name
├── RawExpression.php             — escape hatch (placeholder-mandatory)
├── Identifier.php                — validates table/column names
├── ConnectionConfig.php          — DSN/provided/template config value object
├── ConnectionManager.php         — multi-connection manager (read/write/tenant)
├── Migration.php                 — migration interface (up/down)
├── MigrationRunner.php           — runs migrations with tracking table
├── Seeder.php                    — seeder interface
├── SeedContext.php               — seed context (connection + seed value)
├── SeederRunner.php              — runs seeders with idempotency tracking
├── Factory.php                   — test data factory
├── Relation.php                  — relation definition (hasMany/hasOne/belongsTo)
├── EagerLoader.php               — N+1-safe eager loading (WHERE IN)
├── TrustedDdl.php                — allows DDL in trusted contexts
└── Exceptions/
    └── QueryException.php        — query execution error
```

### 8.7 `validation` — LombokClarion\Validation

```
src/
├── Rule.php                      — fluent rule builder (354 lines, all rule types)
├── Validator.php                 — validates data against rules
├── Violation.php                 — single validation error
├── ValidationException.php       — carries violations array
├── FormRequest.php               — controller-level auto-validation
└── Lang.php                      — loads validation message translations
resources/lang/
└── {ar,bn,de,en,es,fa,fr,hi,id,it,ja,ko,ms,nl,pl,pt,ru,sw,th,tr,uk,ur,vi,zh}.php
```

### 8.8 `security` — LombokClarion\Security

```
src/
├── PasswordHasher.php            — interface (hash/verify/needsRehash)
├── Argon2idPasswordHasher.php    — Argon2id implementation (OWASP cost validated)
├── Encrypter.php                 — interface (encrypt/decrypt)
├── AesGcmEncrypter.php           — AES-256-GCM implementation
├── Encrypted.php                 — typed wrapper for encrypted values
├── CsrfTokenManager.php         — stateless HMAC double-submit CSRF
├── ValidateCsrf.php              — CSRF validation middleware
├── RateLimitStore.php            — rate limit backend interface
├── InMemoryRateLimitStore.php    — in-memory rate limit (per-process)
├── RateLimit.php                 — rate limit middleware factory
├── SecurityHeaders.php           — security headers middleware (CSP, HSTS, etc.)
├── SecurityConfig.php            — security configuration value object
├── FormRequest.php               — secure form request (extends validation FormRequest)
└── Exceptions/
    ├── SecurityException.php     — base security error
    └── ValidationException.php   — security validation error
```

### 8.9 `auth` — LombokClarion\Auth

```
src/
├── AuthManager.php               — orchestrates auth flow (login/logout/check)
├── TokenIssuer.php               — HMAC token creation + verification
├── TokenStore.php                — token storage interface
├── InMemoryTokenStore.php        — testing token store
├── UserProvider.php              — interface for user lookup
├── Authenticatable.php           — interface users must implement
├── Authenticate.php              — middleware: require valid token
├── Authorize.php                 — middleware: require specific ability
├── Gate.php                      — ability definitions + checks
├── Policy.php                    — policy interface (single method per ability)
├── RoleRepository.php            — interface for role lookup
├── DatabaseRoleRepository.php    — database-backed role repository
└── Exceptions/
    └── AuthException.php         — authentication/authorization error
```

### 8.10 `view` — LombokClarion\View

```
src/
├── ViewCompiler.php              — Blade-like template compiler
├── ViewEngine.php                — template rendering engine
├── Theme.php                     — theme resolver (data-style attribute)
├── AssetManifest.php             — content-hashed asset manifest
├── AssetPublisher.php            — copies + hashes assets to public/
├── StaticAssetsMiddleware.php    — serves static assets (traversal-safe)
├── Safe.php                      — marks strings as pre-escaped
├── ViewErrorPageRenderer.php     — error pages using view templates
└── Exceptions/
    └── ViewException.php         — template compilation/rendering error
```

### 8.11 `console` — LombokClarion\Console

```
src/
├── ConsoleKernel.php             — CLI dispatcher
├── Command.php                   — command interface (name/description/run)
└── BuiltIn/
    ├── MigrateCommand.php        — run pending migrations
    ├── MigrateRollbackCommand.php — rollback last migration batch
    ├── MigrateStatusCommand.php  — show migration status
    ├── MakeMigrationCommand.php  — generate migration file
    ├── SeedCommand.php           — run database seeders
    ├── SeedStatusCommand.php     — show seeder status
    ├── MakeSeederCommand.php     — generate seeder file
    ├── OptimizeCommand.php       — compile container/config/routes/assets
    ├── WorkCommand.php           — queue worker process
    ├── AuditSqlCommand.php       — SQL injection audit (TokenScanner)
    ├── AuditSecurityCommand.php  — security audit (CSRF/headers/debug)
    └── TokenScanner.php          — PHP tokenizer for SQL analysis
```

### 8.12 `log` — LombokClarion\Log

```
src/
├── Logger.php                    — logger interface
├── ChannelLogger.php             — multi-handler channel logger
├── NullLogger.php                — no-op logger
├── LogLevel.php                  — log level enum
├── LogRecord.php                 — structured log record value object
├── LogHandler.php                — handler interface
├── LogFormatter.php              — formatter interface
├── LogsConveniently.php          — trait: debug/info/warning/error sugar methods
├── Redactor.php                  — redacts sensitive fields from context
├── Handlers/
│   ├── StreamHandler.php         — file/stream handler (daily rotation, retention)
│   └── InMemoryHandler.php       — test handler (stores records in array)
└── Formatters/
    ├── LineFormatter.php         — human-readable single-line format
    └── JsonFormatter.php         — JSON structured format
```

### 8.13–8.20 Remaining Packages

| Package | Key Files | Status |
|---------|-----------|--------|
| `i18n` | Translator.php, DetectLocale.php | ✅ COMPLETE |
| `storage` | Storage.php (interface), LocalStorage.php | ✅ COMPLETE |
| `active-record` | Model.php, ModelQueryBuilder.php, Exceptions/ActiveRecordException.php | ✅ COMPLETE |
| `facades` | Facade.php, Bus.php, Event.php, Hash.php | ✅ COMPLETE |
| `laravel-flavor` | LaravelFlavor.php, Auth.php, DB.php | ✅ COMPLETE |
| `testing` | HttpTestCase, FakeCommandBus, FakeEventBus, InMemoryRepository, ConsoleTestCase, BenchmarkTestCase, ColdStartTest | ✅ COMPLETE |
| `phpstan-rules` | DomainBoundaryRule.php, NoRawSqlValuesRule.php | ✅ COMPLETE |
| `framework` | metapackage (composer.json only) | ✅ COMPLETE |

---

## 9. Test Suite

| Test File | Focus | Lines |
|-----------|-------|-------|
| AuthTest.php | Token issuance, RBAC, Gate/Policy, login/logout | 535 |
| SeederToolingTest.php | Seeder runner, idempotency, seed status | 510 |
| ValidationTest.php | All rule types, i18n messages, FormRequest | 462 |
| PersistenceTest.php | QueryBuilder, SchemaBuilder, joins, subqueries | 426 |
| ConsoleTest.php | All 12 CLI commands | 400 |
| ErrorPageTest.php | Error handler, plain/view renderers, debug mode | 372 |
| LoggingTest.php | Channels, handlers, formatters, redaction | 337 |
| ConnectionManagerTest.php | Multi-DB, read/write, tenants, failover | 325 |
| MigrationToolingTest.php | Migration runner, status, rollback | 301 |
| ActiveRecordAndFacadesTest.php | Model CRUD, eager-loading, facades | 233 |
| RouteCompilationTest.php | Route compiler, compiled matcher | 210 |
| QueueTest.php | Queue stores, worker, retry, failed jobs | 191 |
| MultiTenancyTest.php | Tenant resolution, DB isolation | 189 |
| LaravelFlavorTest.php | Auth/DB preset, helpers | 187 |
| StorageTest.php | LocalStorage, path traversal blocking | 176 |
| ContainerTest.php | Bindings, compilation, circular detection | 181 |
| TokenScannerTest.php | SQL injection detection | 163 |
| AssetsAndThemeTest.php | Asset manifest, content hashing, themes | 139 |
| CompiledBootParityTest.php | Dev vs compiled boot equivalence | 142 |
| I18nTest.php | Translator, locale detection | 125 |
| SecurityTest.php | Argon2id, CSRF, rate limit, encryption | 123 |
| TestingPackageTest.php | FakeCommandBus, FakeEventBus, InMemory | 115 |
| BusTest.php | Command/Query/Event dispatch | 100 |
| PluginTest.php | Plugin registration, capability check | 81 |
| RoutingTest.php | Router matching, groups, params | 78 |
| ConfigTest.php | Config compiler, env resolution | 67 |
| ViewTest.php | Template compilation, auto-escape | 55 |

---

## 10. CI/CD & Deploy

| File | Purpose |
|------|---------|
| `.github/workflows/ci.yml` | Run test suite |
| `.github/workflows/split.yml` | Subtree split to per-package repos |
| `.github/workflows/publish.yml` | Tag-triggered Packagist publish |
| `.github/workflows/npm-publish.yml` | npm publish for JS assets |
| `.github/workflows/codeql.yml` | CodeQL security scanning |
| `.github/workflows/ossar.yml` | OSSAR security analysis |
| `Dockerfile` | PHP 8.3 + extensions |
| `docker-compose.yml` | App + DB + worker |
| `deploy/nginx.conf` | Nginx configuration |
| `deploy/db-roles.sql` | Postgres least-privilege roles |
| `deploy/lombokclarion-worker.service` | systemd worker service |

---

## 11. Package README Enrichment Status

Each package has a basic README (22–36 lines) with install + namespace + license.
These need enriching with usage examples, API reference, and architecture notes.

---

## 12. Dependency Graph

```
container ← (everything)
     │
config ← (standalone, used by optimize)
     │
http ← persistence, log
  │
routing ← http, container
  │
bus ← container (queue: persistence optional)
  │
persistence ← (standalone)
  │
validation ← i18n
  │
security ← http (middleware), container
  │
auth ← http, persistence, security, container
  │
view ← http (middleware)
  │
console ← container, persistence, routing, security, auth
  │
log ← (standalone)
  │
i18n ← http (middleware)
  │
storage ← http (UploadedFile)
  │
active-record ← persistence (forbidden from Domain)
  │
facades ← container (forbidden from Domain)
  │
laravel-flavor ← auth, persistence, facades
  │
testing ← container, http, bus, persistence
  │
phpstan-rules ← (standalone PHPStan extension)
```

---

## 13. Key Design Decisions

1. **No auto-discovery** — every binding, route, command, migration is explicitly registered
2. **Domain purity** — `app/Domain/**` has zero `LombokClarion\*` imports (enforced by TokenScanner)
3. **Compiled boot** — `optimize` produces flat PHP files; zero reflection at request time
4. **Stateless auth** — HMAC tokens, no server-side sessions
5. **Single handler per command** — no multi-dispatch; events have multiple listeners
6. **Magic is opt-in** — ActiveRecord + Facades are separate packages with `forbidden-layers`
7. **Safe by construction** — QueryBuilder has no raw-value API; view auto-escapes by default
8. **Explicit externals** — PDO/ConnectionManager declared non-serializable, re-bound per boot

---

## 14. Quick Reference: Bootstrap File → Package Mapping

| Bootstrap | Wires |
|-----------|-------|
| `services.php` | container, persistence, bus, security, auth, storage, view, log, http |
| `routes.php` | routing, security (CSRF/RateLimit), auth (Authenticate) |
| `console.php` | console, persistence (migrations/seeds), bus (worker), security (audit) |
| `externals.php` | persistence (PDO, ConnectionManager) |
| `migrations.php` | persistence (migration class manifest) |
| `seeders.php` | persistence (seeder class manifest) |

---

## 15. Session-Resume Checklist

When resuming work in a new session, read this document + memory file. Key files to re-check:

1. `docs/ARCHITECTURE-MAP.md` (this file)
2. `docs/PROJECT-SUMMARY.md` (design spec compliance)
3. `docs/AUDIT-TRAIL.md` (all findings and fixes)
4. `bootstrap/services.php` (container wiring)
5. `bootstrap/routes.php` (route registration)
6. `bootstrap/console.php` (CLI commands)
7. `app/Infrastructure/ServiceFactories.php` (factory methods)
8. `tests/harness.php` (test framework)
9. `composer.json` (root monorepo config)

---

## 16. NEW PACKAGES (Stage 18 — Full-Stack Completion)

10 packages added to make LombokClarion a complete full-stack framework.

### 16.1 `cache` — LombokClarion\Cache (7 files)

```
src/
├── Cache.php                     — cache interface (get/put/has/forget/flush/remember/increment)
├── CacheManager.php              — named-store manager (same pattern as ConnectionManager)
├── Drivers/
│   ├── FileCacheDriver.php       — filesystem cache (2-level hash dirs, atomic writes)
│   ├── DatabaseCacheDriver.php   — PDO-backed cache with auto-create table
│   ├── ArrayCacheDriver.php      — in-memory cache for testing
│   └── NullCacheDriver.php       — no-op driver
└── Exceptions/
    └── CacheException.php
```

### 16.2 `mail` — LombokClarion\Mail (12 files)

```
src/
├── Mailer.php                    — renders Mailable through ViewEngine, dispatches via Transport
├── Mailable.php                  — base class (envelope + content + attachments)
├── Envelope.php                  — from/to/cc/bcc/replyTo/subject (immutable builder)
├── Content.php                   — view template, raw html, or plain text
├── Address.php                   — validated email address VO
├── Attachment.php                — file attachment VO
├── Transport/
│   ├── Transport.php             — transport interface
│   ├── SmtpTransport.php         — real SMTP via socket (STARTTLS, AUTH LOGIN, MIME)
│   ├── SendmailTransport.php     — local sendmail/MTA via mail()
│   ├── LogTransport.php          — logs instead of sending (dev)
│   └── ArrayTransport.php        — captures messages for test assertions
└── Exceptions/
    └── MailException.php
```

### 16.3 `session` — LombokClarion\Session (7 files)

```
src/
├── Session.php                   — session bag (data, flash, token, regenerate)
├── SessionHandler.php            — handler interface (read/write/destroy/gc)
├── StartSession.php              — middleware (cookie read → start → save → cookie write)
├── Handlers/
│   ├── FileSessionHandler.php    — file-based handler
│   ├── DatabaseSessionHandler.php — PDO-backed handler
│   └── ArraySessionHandler.php   — in-memory handler for testing
└── Exceptions/
    └── SessionException.php
```

### 16.4 `notification` — LombokClarion\Notification (8 files)

```
src/
├── Notification.php              — base class (via() → channel list)
├── Notifiable.php                — interface for notification recipients
├── NotificationChannel.php       — channel interface (name + send)
├── NotificationDispatcher.php    — routes notifications to declared channels
├── Channels/
│   ├── DatabaseChannel.php       — stores in DB (unread, markAsRead)
│   ├── MailChannel.php           — sends via Mailer
│   └── LogChannel.php            — logs for dev/testing
└── Exceptions/
    └── NotificationException.php
```

### 16.5 `websocket` — LombokClarion\WebSocket (6 files)

```
src/
├── Connection.php                — connection VO (id, sender, userId, attributes)
├── ConnectionManager.php         — tracks all active connections, broadcast, forUser()
├── MessageSender.php             — interface for runtime-specific send/close
├── WebSocketHandler.php          — app-level handler (onOpen/onMessage/onClose/onError)
├── RoomManager.php               — room-based pub/sub (join/leave/broadcast)
└── Exceptions/
    └── WebSocketException.php
```

### 16.6 `vite` — LombokClarion\Vite (2 files)

```
src/
├── Vite.php                      — manifest reader, dev/prod tag generation, preload
└── ViteDevMiddleware.php         — auto-injects Vite client in dev mode
```

### 16.7 `inertia` — LombokClarion\Inertia (2 files)

```
src/
├── Inertia.php                   — server-side adapter (render, share, location)
└── InertiaMiddleware.php         — version checking, POST→303 redirect fix
```

### 16.8 `cloud-storage` — LombokClarion\CloudStorage (3 files)

```
src/
├── Drivers/
│   ├── S3Storage.php             — AWS S3 (+ MinIO/R2/Spaces) via curl + Sig V4
│   └── GcsStorage.php            — Google Cloud Storage via JSON API + JWT
└── Exceptions/
    └── CloudStorageException.php
```

### 16.9 `scheduler` — LombokClarion\Scheduler (6 files)

```
src/
├── Scheduler.php                 — registers tasks, runs due ones
├── ScheduledTask.php             — task definition with fluent cron helpers
├── CronExpression.php            — pure PHP cron expression parser/matcher
├── ScheduleRunCommand.php        — CLI: schedule:run
├── ScheduleListCommand.php       — CLI: schedule:list
└── Exceptions/
    └── SchedulerException.php
```

### 16.10 `broadcasting` — LombokClarion\Broadcasting (9 files)

```
src/
├── Broadcaster.php               — broadcaster interface
├── BroadcastManager.php          — dispatches ShouldBroadcast events
├── ShouldBroadcast.php           — marker interface for broadcastable events
├── Channel.php                   — channel VO (public/private/presence)
├── ChannelType.php               — enum (Public/Private/Presence)
├── Drivers/
│   ├── DatabaseBroadcaster.php   — stores in DB table (poll/SSE pattern)
│   ├── LogBroadcaster.php        — logs events (dev)
│   └── NullBroadcaster.php       — no-op
└── Exceptions/
    └── BroadcastException.php
```

---

## 17. Updated Capability Matrix

### NOW HAS (full-stack)

| Capability | Package | Key Classes |
|-----------|---------|-------------|
| Cache | `cache` | Cache, CacheManager, FileCacheDriver, DatabaseCacheDriver |
| Mail/SMTP | `mail` | Mailer, Mailable, SmtpTransport, SendmailTransport |
| Session | `session` | Session, StartSession, FileSessionHandler, DatabaseSessionHandler |
| Notification | `notification` | NotificationDispatcher, DatabaseChannel, MailChannel |
| WebSocket | `websocket` | Connection, ConnectionManager, RoomManager, WebSocketHandler |
| Vite integration | `vite` | Vite, ViteDevMiddleware |
| SPA (Inertia) | `inertia` | Inertia, InertiaMiddleware |
| Cloud storage | `cloud-storage` | S3Storage, GcsStorage |
| Scheduler/Cron | `scheduler` | Scheduler, ScheduledTask, CronExpression |
| Broadcasting | `broadcasting` | BroadcastManager, DatabaseBroadcaster, Channel |

### Framework totals

- **30 packages** (was 20)
- **250 PHP source files** (was 188)
- **22 runtime packages** in `lombokclarion/framework` metapackage
- **4 suggested packages** (websocket, vite, inertia, cloud-storage)
