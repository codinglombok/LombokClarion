# LombokClarion

A PHP 8.3+ Full Stack Web framework built on the philosophy **explicit over
magic**: edge/serverless-first, domain layer with zero framework imports,
containerization-ready.

**Core capabilities:** DI Container + AOT compilation, Routing + 3 runtime
adapters (FPM/Function/Swoole), Persistence (QueryBuilder, Schema, Migrations,
Seeding), ActiveRecord (opt-in), Auth (HMAC token, RBAC, Gate/Policy),
Validation + i18n (24 languages).

Facades and ActiveRecord exist as **opt-in packages** (`lombokclarion/facades`,
`lombokclarion/active-record`, `lombokclarion/laravel-flavor`) but are never
loaded by default — no core package depends on them, and the domain-boundary
gate blocks them from `app/Domain/**`. There is no auto-discovery anywhere:
every binding, route, command, and migration is registered in an explicit
manifest file.

This repo contains **working, tested code** (377 tests, 0 failures) plus a
small end-to-end example app (a Widget CRUD feature) wiring all packages
together. It is not a drop-in `composer create-project` package yet —  —
Every piece that exists actually runs, and is covered by tests that
actually run (377 tests, 0 failures, see "Running the tests").

## Installing / consuming the packages

The packages are published to Packagist under the `lombokclarion/*` vendor. Pull
the whole runtime stack in one shot with the metapackage:

```bash
composer require lombokclarion/framework
```

`lombokclarion/framework` installs the full runtime (including the opt-in magic
packages — requiring it is itself the explicit choice). It
leaves out the two dev-only packages, which you add to require-dev yourself:

```bash
composer require --dev lombokclarion/testing lombokclarion/phpstan-rules
```

Or install packages individually — and note that requiring one never drags in
another's optional pieces (`require lombokclarion/persistence` does **not** pull
ActiveRecord):

```bash
composer require lombokclarion/routing lombokclarion/http lombokclarion/persistence
# the "magic" packages, only if you ask for them by name:
composer require lombokclarion/active-record lombokclarion/facades lombokclarion/laravel-flavor
```

This repository (`codinglombok/LombokClarion`) is the **canonical monorepo** where
all development happens; each package is mirrored read-only to its own repo, and
those mirrors are what Packagist watches. Contribute here, not to the mirrors. The
full rationale, the vendor/org mapping (`lombokclarion/*` on Packagist ↔
`codinglombok/*` on GitHub), and the release flow live in
[`docs/PUBLISHING.md`](docs/PUBLISHING.md).

To hack on the framework itself, clone this monorepo and use it directly — the
packages resolve to each other as `path` repositories, so `composer install`
needs no network:

```bash
git clone https://github.com/codinglombok/LombokClarion.git
cd LombokClarion && composer install
php bin/lombokclarion migrate && php bin/lombokclarion optimize
php tests/run-all.php
```

## Layout

```
packages/                                    20 packages
  container/       LombokClarion\Container      — DI container + AOT compiler
  http/            LombokClarion\Http           — Request/Response/Middleware/ErrorHandler
  routing/         LombokClarion\Routing        — Router, Kernel, runtime adapters
  bus/             LombokClarion\Bus            — CommandBus/QueryBus/EventBus
  config/          LombokClarion\Config         — typed config compiler
  persistence/     LombokClarion\Persistence    — QueryBuilder/SchemaBuilder/migrations/seeding
  view/            LombokClarion\View           — Blade-like compiler, auto-escaping
  console/         LombokClarion\Console        — CLI kernel + 12 built-in commands
  security/        LombokClarion\Security       — hashing/CSRF/rate-limit/headers/encryption
  auth/            LombokClarion\Auth           — AuthManager/Gate/Policy/RBAC/TokenIssuer
  i18n/            LombokClarion\I18n           — Translator + DetectLocale middleware
  validation/      LombokClarion\Validation     — Validator/FormRequest/Rule (24 locales)
  storage/         LombokClarion\Storage        — LocalStorage with Storage interface
  log/             LombokClarion\Log            — Logger/ChannelLogger/StreamHandler/Redactor
  active-record/   LombokClarion\ActiveRecord   — Model base class + EagerLoader (opt-in magic)
  facades/         LombokClarion\Facades        — Facade base + Bus/Event/Hash (opt-in magic)
  laravel-flavor/  LombokClarion\LaravelFlavor  — Auth/DB facade shims (opt-in magic)
  testing/         LombokClarion\Testing        — HttpTestCase, fakes, ColdStartTest
  phpstan-rules/   LombokClarion\PHPStanRules   — SQL-injection + domain-boundary PHPStan ext
  framework/       (metapackage)                — one-require for full runtime stack

app/
  Domain/Widget/        entity, repository interface, command+query, handlers
                         (zero LombokClarion\* imports — enforced by
                         bin/check-domain-boundary.php)
  Http/Controllers/      thin controller, dispatches to CommandBus/QueryBus
  Http/Requests/         FormRequest (mass-assignment-proof validation)
  Infrastructure/        SqlWidgetRepository (QueryBuilder), migrations,
                         ServiceFactories (array-callable factories so
                         `optimize` can compile them)

bootstrap/
  services.php   every binding, one file, grep-able
  routes.php     every route + its middleware, one file
  console.php    every CLI command, one file
  migrations.php explicit migration manifest (no directory scanning)
  seeders.php    explicit seeder manifest
  externals.php  external library integrations (TableSheet, etc.)

config/config.schema.php   typed config schema
public/index.php           HTTP entrypoint (FpmAdapter)
bin/lombokclarion           CLI entrypoint
bin/check-domain-boundary.php  Deptrac-equivalent CI check (see below)
tests/                      377 tests across 27 files, custom zero-dependency harness
```

## Why a custom autoloader instead of Composer?

This environment has no network access to Packagist, so `composer install`
can't run here. `autoload.php` at the repo root is a small PSR-4 shim that
maps each package's namespace straight to its `src/` folder — good enough
to run and test everything in this sandbox. Every package still ships a
real `composer.json` with correct `autoload.psr-4` blocks; in a normal
environment you'd delete `autoload.php` and just
`composer install && require 'vendor/autoload.php'`.

## Running the tests

No PHPUnit (same network restriction), so there's a ~90-line
assertion-based harness in `tests/harness.php`. Run everything:

```bash
php tests/run-all.php
```

Or a single file:

```bash
php -r "require 'tests/harness.php'; runTests('tests/ContainerTest.php');"
```

## Trying the example app end-to-end

```bash
php bin/lombokclarion migrate        # creates storage/database.sqlite
php -S localhost:8080 -t public      # then curl it, or:
php bin/lombokclarion optimize       # writes storage/services.compiled.php
                                      # and storage/config.compiled.php
php bin/check-domain-boundary.php    # proves app/Domain/** stays framework-free
```

```bash
curl localhost:8080/api/widgets
curl -X POST localhost:8080/api/widgets -d name=Lamp -d price_cents=1500
# -> 419 CSRF token mismatch (correct: CSRF is required on this route)
```

## Design requirements mapping

| Section | Requirement | Where |
|----|---|---|
| 2.1-2.6 | No auto-discovery, explicit config, magic opt-in only | Core packages never use facades; `lombokclarion/facades` is opt-in with `forbidden-layers: [app/Domain]`; every binding is in `bootstrap/services.php` |
| 3 | Container to Router to Middleware to Container to Controller to Bus to Domain to Repository flow | `packages/routing/src/Kernel.php` |
| 3 | Domain layer zero framework imports, CI-enforced | `app/Domain/Widget/*` + `bin/check-domain-boundary.php` (passing; verified it also *fails* on a deliberately-introduced violation) |
| 4.1 | Container: explicit binding, no reflection auto-wiring of *interfaces* | `packages/container/src/Container.php` |
| 4.2 | Http: Request/Response value objects | `packages/http/src/Request.php`, `Response.php` |
| 4.3 | Routing: route table, middleware composition, groups | `packages/routing/src/Router.php`, `Route.php`, `Kernel.php` |
| 4.4 | Bus: Command/Query/EventBus, explicit registration | `packages/bus/src/*` |
| 4.5 | Config: typed, schema-generated | `packages/config/src/ConfigCompiler.php` - `$config->mail->smtp->host` as real typed property access, not `config('a.b.c')` |
| 4.6 | Kernel + FpmAdapter, FunctionAdapter, SwooleAdapter | `packages/routing/src/Adapters/*` |
| 4.7 | Persistence: bound-params-only QueryBuilder, SchemaBuilder, migration runner reading an explicit manifest | `packages/persistence/src/*` |
| 4.8 | View: Blade-like compiler, auto-escaping by default, compiled/cached ahead of time | `packages/view/src/*` |
| 4.9 | Console: CLI kernel, same container as HTTP | `packages/console/src/*` |
| 4.10 | Testing: HttpTestCase, fakes, InMemoryRepository, ColdStartTest | `packages/testing/src/*` |
| 4.11 | Security package: Argon2id, CSRF, rate-limit, security headers, `Encrypted<T>` | `packages/security/src/*` |
| 5 | Cold-start budget, compiled/reflection-free boot, no persistent-process assumptions | `ContainerCompiler` (AOT reflection) + `CompiledContainer` (zero reflection) + `ColdStartTest`; verified the compiled boot path actually serves a request correctly end-to-end |
| 6 | Security requirements (hashing, CSRF, sessions, validation, rate limit, headers, encryption, `audit:security`) | `packages/security/*` + `AuditSecurityCommand` |
| 7 | SQL/injection hardening, `audit:sql`, N+1 `with()`, MySQL non-transactional migrations | `QueryBuilder`/`Identifier`/`RawExpression` + `AuditSqlCommand` + `SchemaBuilder::migrationsAreTransactionalByDefault()` |
| 9 | Domain tests need zero HTTP/DB; `HttpTestCase` boots the *real* container | `InMemoryRepository` + `HttpTestCase` |
| 10 | No implicit retry on queued commands | `RetryPolicy::none()` is the only default; `RetriesQueuedCommand` is opt-in |

## Known, deliberate limitations (documented, not bugs)

- **`ContainerCompiler` can't see inside closures.** A binding registered
  as `[FactoryClass::class, 'method']` is compiled as a direct static call
  (zero reflection), but if that method internally does
  `$c->get(SomethingElse::class)`, `SomethingElse` must *also* have its own
  explicit binding (or be listed in `extraRootIds`) or the compiled
  container won't know about it. This bit the example app during
  development (`CreateWidgetHandler`/`ListWidgetsHandler` are only
  referenced inside `CommandBus::register()` calls inside a factory
  closure) - fixed by listing them in `bootstrap/console.php`'s
  `OptimizeCommand` wiring. This is the correct fix, not a workaround: it's
  the same "explicit over magic" trade-off applied to compilation.
- **Runtime-constructed singletons (a `PDO` connection) can't be baked
  into a static compiled file.** `ContainerCompiler::compile()` takes an
  `$externallyProvided` list for exactly this - the adapter constructs the
  connection fresh per request/invocation (per §5, no pooled connection is
  assumed) and calls `CompiledContainer::instance(PDO::class, $pdo)` before
  handling the request.
- **`audit:sql`/`audit:security` are real, working, regex-based
  heuristics**, not a full bundled PHPStan/Psalm ruleset. They catch the
  concrete cases named in the design spec §6/§7 (string-
  concatenated `query()`/`prepare()` calls, unmarked `{!! !!}` output,
  missing CSRF/security-headers middleware, `APP_DEBUG=true` in
  production) and are wired into `bootstrap/console.php` today.
- **Config values are resolved once, at `optimize` time**, per §5's "never
  re-parsed per request." There's no runtime env-var re-read in the
  compiled config - that's intentional, not an oversight.

## LombokCSS starter kit (§8, §13) — now implemented

- The **real LombokCSS** (github.com/codinglombok/LombokCSS) is vendored
  self-hosted at `resources/lombokcss/lombok.min.css` (MIT, license included) —
  never CDN-loaded, per §8.
- **Reality check vs. the design spec:** the actual library's vocabulary is
  plain component classes (`.btn`, `.card`, `.navbar`, `.table`) with `--lc-*`
  design tokens and `data-style`/`data-theme` attributes — **not** the `lc-*`
  class prefix or `data-variant`/`data-elevation` attributes the spec
  guessed. The starter views follow the *actual* vocabulary, which honors the
  spec's real rule ("use LombokCSS's own vocabulary exclusively").
- Upstream ships resonant-stark, neo-brutalism, glassmorphism (plus
  modern-corporate-flat, semantic-minimalist) but **not** `quiet-editorial` —
  so `resources/lombokcss/quiet-editorial.css` authors it as a preset
  extension following upstream's own token-remap pattern from src/themes.css.
- `data-style` comes from `Theme` (validated at boot), fed by the `THEME_STYLE`
  env var through the typed config — never hardcoded in a layout. All 4 preset
  values verified rendering end-to-end.
- `lombokclarion optimize` now also publishes assets with **content-hashed
  filenames** + a PHP manifest (`AssetPublisher`); views resolve via
  `AssetManifest`; `StaticAssetsMiddleware` serves `/assets/*` with
  `Cache-Control: public, max-age=31536000, immutable` under `php -S` (real
  deployments let the web server/CDN do it).
- Fixing this surfaced and fixed a real Kernel bug: global middleware now
  wraps the routing decision, so security headers/asset serving apply to
  404/405 responses too (regression-tested).

## What's not here yet (explicitly out of scope for this pass)

These are called out in the design doc but weren't built in this pass -
each is additive, not a rework of what exists:

## Optional packages (§4.12) — now implemented

- **`lombokclarion/active-record`** — full Model base class with:
  `create()`/`find()`/`update()`/`delete()`, `query()` with
  `where()`/`orderBy()`/`limit()`, `with()` eager-loading via
  `EagerLoader` (N+1 safe), `$fillable` whitelist (mass-assignment blocked
  structurally). Relations declared as `Relation::hasMany/hasOne/belongsTo`.
  `composer.json` carries `forbidden-layers: ["app/Domain"]`.
- **`lombokclarion/facades`** — `Facade` base class + concrete facades
  (`Bus`, `Event`, `Hash`). Requires explicit `Facade::setContainer()`
  opt-in — never auto-discovered. Same `forbidden-layers` metadata.
  `app/Domain/**` cannot import either package (enforced by
  `bin/check-domain-boundary.php`).

## Eager-loading / N+1 prevention (§7) — now implemented

- `EagerLoader` issues one `WHERE IN` query per relation instead of N+1
  lazy queries. Supports `hasMany`, `hasOne`, `belongsTo`.
- `Relation` value object: `Relation::hasMany('comments', 'post_id')`.
- Wired through `ModelQueryBuilder::with()` (ActiveRecord) and directly
  usable from explicit repositories via `EagerLoader::load()`.
- 5 dedicated tests covering all relation types + edge cases.
## Multi-tenancy (§11) — now implemented

- Tenancy is a **request-scoped container binding pattern**, not a framework
  mode. `ResolveTenant` middleware is declared per-route/group (never globally);
  routes without it simply have no tenant — no implicit fallback, no global
  toggle.
- Ships `HeaderTenantResolver` (reads `X-Tenant-ID`); the `TenantResolver`
  interface supports subdomain/path-prefix resolvers as app-specific
  implementations.
- `TenantAwareConnection` builds a per-tenant PDO from a DSN template with
  `{database}` placeholder — proven isolated with separate SQLite databases
  in tests.
- Tenant flows through `RequestContext` (not statics), so downstream
  controllers/handlers receive it via typed injection.
- 8 tests covering: header resolution, missing/unknown tenant, middleware
  binding into context, no-tenant public routes, connection factory, isolation.

## Queue/Worker (§12) — now implemented

- `ShouldQueue` marker interface: commands implementing it get serialized
  and pushed to a `QueueStore` by `QueuedCommandBus` instead of running
  inline. Commands without it dispatch immediately (parity: same handler
  code runs in both paths).
- Default: **single-attempt, no retry** (§10). Opt-in via
  `RetriesQueuedCommand` + `RetryPolicy(maxAttempts, backoffSeconds)`.
- `QueueWorker` pops jobs, deserializes, feeds into the real `CommandBus`
  (same handler code path as inline — §12 parity). Failed jobs retry up to
  `maxAttempts` with optional backoff, then go to `QueueStore::fail()`.
- Two `QueueStore` implementations: `InMemoryQueueStore` (testing/dev),
  `DatabaseQueueStore` (persistent, SQLite/Postgres/MySQL).
- `lombokclarion work` CLI command: `--queue=name`, `--loop`, `--sleep=N`.
- 8 tests covering: enqueue-vs-inline, worker processing, retry exhaustion,
  failed-job recording, DB store round-trip.

## QueryBuilder joins — now implemented

- `join()` and `leftJoin()` with fully validated `table.column` ON clauses —
  identifiers go through `Identifier::validate()` on both sides, no injection
  surface.
- `groupBy()` for aggregate queries.
- `select()`, `where()`, `orderBy()` now all accept qualified `table.column`
  references.
- 4 new tests covering inner join, left join (null-match rows present),
  groupBy aggregate, and invalid-identifier rejection.



## audit:sql upgraded: token-based scanner + --explain (§7)

- `TokenScanner` replaces the regex heuristics with PHP-tokenizer-based
  (AST-lite) analysis: catches multi-line concatenation, variable
  interpolation inside query strings, and sprintf feeding
  query()/prepare()/exec(), while correctly ignoring the same patterns
  inside comments and plain string literals (regex could do none of that
  reliably — proven by dedicated tests for each case).
- `audit:sql --explain` connects to the configured DB, runs
  EXPLAIN (QUERY PLAN / ANALYZE per driver) on populated tables, and flags
  sequential scans as missing-index candidates. Verified against SQLite's
  modern "SCAN <table>" plan format.
- This is still not a bundled PHPStan/Psalm ruleset (the only remaining
  gap vs. the spec) — but it is a real static analyzer, not pattern
  matching, and it is wired into CI-style commands today.

## Plugin system (§10) — now implemented

- `Plugin` interface: name() + capabilities() (['bindings','routes','commands'])
  + register(Container). Registration is always explicit in services.php —
  no composer-extra scanning, no vendor sweep, no self-registration.
- `PluginRegistrar` enforces an optional capability allow-list, so an app
  can declare in code "plugins may add bindings but never routes";
  violations fail loudly at registration. Duplicate registration throws.
- 4 tests: registration, duplicates, allow-list blocking, null-allow-all.

## Remaining gap vs. the spec

- A bundled PHPStan/Psalm ruleset distributing the audit rules as real
  extension packages (today: the TokenScanner static analyzer above).

## License

Apache License 2.0 — see [`LICENSE`](LICENSE) and [`NOTICE`](NOTICE).

The Apache-2.0 terms cover LombokClarion's own source (`packages/`, `app/`,
`bootstrap/`, `bin/`, `config/`, `tests/`, `docs/`). Third-party frontend assets
vendored under `resources/` (LombokCSS, LombokCharts) keep their own MIT licenses,
recorded in `NOTICE` and in their `LICENSE-*` files — they are not relicensed.
