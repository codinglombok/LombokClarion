# Changelog

## 1.0.0
Built in 7 staged passes (full detail: docs/AUDIT-TRAIL.md).

- **Stage 1** — Core: Container (+AOT compiler → zero-reflection CompiledContainer),
  Http, Routing (+Fpm/Function/Swoole adapters), Bus, Config compiler, Persistence
  (bound-params-only QueryBuilder, migrations manifest), View (auto-escaping),
  Console (migrate/optimize/audits), Security (Argon2id/CSRF/RateLimit/Headers/
  AES-GCM/FormRequest), Testing (fakes, ColdStartTest), example Widget app,
  domain-boundary checker. 67 tests.
- **Stage 2** — LombokCSS starter kit: vendored upstream dist, quiet-editorial theme
  extension, Theme/AssetPublisher/AssetManifest/StaticAssetsMiddleware, HTML pages.
  Fixed real Kernel bug: global middleware now wraps routing (runs on 404). 77 tests.
- **Stage 3** — Multi-tenancy (§11), Queue/Worker (§12), QueryBuilder joins/groupBy. 97 tests.
- **Stage 4** — EagerLoader (N+1), optional packages active-record & facades
  (forbidden-layers enforced). 110 tests.
- **Stage 5** — TokenScanner (tokenizer-based audit:sql), --explain, Plugin system,
  work-command wiring fix. 122 tests.
- **Stage 6** — LombokCharts dashboard (script-breakout-safe JSON embedding). 124 tests.
- **Stage 7** — Deployment tooling: Dockerfile (base/worker/cloudrun), compose,
  nginx, systemd, GitHub Actions CI, deployment guide for GitHub/VPS/Docker/GCP/AWS/DO.
  124 tests.
- **Stage 8** — Gap closure: `lombokclarion/phpstan-rules` extension package
  (raw-SQL + domain-boundary AST rules) and `deploy/db-roles.sql` least-privilege
  Postgres template. 124 tests.
- **Stage 9** — `lombokclarion/auth`: stateless HMAC session tokens (TokenIssuer),
  AuthManager on the existing PasswordHasher + RequestContext (no static Auth::user()),
  Authenticate/Authorize middleware, hand-registered RBAC Gate + policies, and four
  migrations. Extends audit:security to warn on unauthenticated mutating routes
  (--public / --strict). Fixes a latent bug where RequestContext was resolved fresh per
  get() (#35) and widens ContainerInterface with instance()/hasInstance(). 186 tests,
  auth exercised end-to-end through the Kernel. See AUDIT-TRAIL #34–37.
- **Stage 8c** — Ran the two gates that had never been executed (PHPStan, `composer
  install`) and fixed what they found: a variable-indirection bypass in both SQL audits,
  `audit:sql` never scanning `packages/`, 8 false positives, and three packages missing
  from `composer.json`. Adds `TrustedDdl`, `phpstan.neon`, `audit:sql --exclude`, and CI
  steps for PHPStan + a shim-free Composer run. 144 tests. See AUDIT-TRAIL #28–33.
- **Stage 8b** — i18n: `lombokclarion/i18n` (Translator, DetectLocale middleware, 24 world-language catalogs, CI-assertable missing-key tracking). 131 tests.
- **Stage 10** — Multi-database ConnectionManager (typed ConnectionConfig: dsn /
  provided / {database} template; lazy, cached), read/write split (identity when
  unconfigured), Grammar seam (AnsiGrammar/MySqlGrammar — quoting only, validation
  unchanged; QueryBuilder + EagerLoader), TenantAwareConnection delegates through
  the manager, `migrate --connection=`. 214 tests.

- **Stage 11** — `laravel-flavor` preset (`LaravelFlavor::enable($container)`: one
  greppable line wiring facades incl. new Auth/DB, AR connection via ConnectionManager
  write side, returned default middleware; `disable()` undoes it; forbidden-layers
  applies), compiled routes (`optimize` → routes.compiled.php match index with
  order-sensitive staleness fingerprint; lazy Route regex; worst-case 500-route match
  ~0.036ms vs 0.5ms budget), `paginate()` (data+meta, clamped page / throwing per_page),
  typed route params (`{id:int}` narrows AND casts). 235 tests.
- **Consolidation pass** — cold-boot benchmark (benchmarks/: methodology +
  measured ~3.2ms dev / ~3.4ms optimized, Laravel counterpart script pending
  same-machine run; comparative claim annotated as qualified) which surfaced
  findings #40–43: compiled container/config were write-only artifacts (now
  loaded by public/index.php via shared bootstrap/externals.php), RequestContext
  explicit + Authenticate lazy class-string + AuthController in compiled roots,
  user:create command + NOT NULL VARCHAR PKs (silent NULL-id login bug),
  Request header keys normalized at construction (#34's documented trap
  disarmed). CompiledBootParityTest boots BOTH paths end-to-end; CI builds
  artifacts before the suite. Stage 12 draft documented internally. 240 tests.
- **Stage 12** — `lombokclarion/validation`: typed rule vocabulary (no pipe-string
  DSL), validate+canonicalize in one step (int/bool/DateTimeImmutable casts,
  rollover dates rejected), whitelist output, all-violations collection,
  FormRequest<TDto> with explicit map() (zero reflection), ValidationException
  implements RendersResponse → Kernel 422 (other exceptions still bubble),
  validation.* messages in all 24 i18n catalogs. 257 tests.
- **Deploy checklist** — pre-deploy walk of the shipped artifact surfaced #44
  (silent fallback to the public dev HMAC key in production → appKey() boot guard,
  unset APP_ENV counts as production) and #45 (down() authored everywhere,
  executable nowhere → MigrationRunner::rollback + migrate:rollback command +
  written rollback runbook, backup-before-migrate first). DEPLOYMENT.md gains the
  first-admin user:create step; .env.example documents LOMBOKCLARION_PASSWORD.
  262 tests.
- **First consumer (SIGAP-MP Tahap 1)** — #46: whereNull()/whereNotNull() (IS NULL
  inexpressible via bound params; dedicated methods, 'is' operator stays rejected).
  Open packaging gap: validation.* catalogs should ship with the package. 263 tests.
- **Validation i18n packaging** — #47: validation.* catalogs (24 locales) now ship
  inside lombokclarion/validation behind `Lang::catalog()` (explicit loader,
  hardcoded LOCALES, hard error on unknown locale); app catalogs keep app keys
  only and may override by addCatalog() order. New parity + no-smuggling gates.
  265 tests.
- **Stage 13** — `lombokclarion/storage`: `Storage` interface + `LocalStorage`
  (relative paths only, `assertSafePath()` hard-errors on traversal; missing
  get/delete are errors, not nulls; `store()` = generated random name +
  caller-explicit validated extension — the client filename touches nothing),
  `UploadedFile::moveTo()` (provenance-aware: move_uploaded_file for SAPI
  uploads, rename otherwise; single-shot), `Rule::file(mimes)->maxBytes()`
  (mime sniffed from BYTES via finfo, never the client header), FormRequest
  merges `$request->files` (UPLOAD_ERR_NO_FILE = absent, so nullable() works),
  validation.file.* keys in all 24 catalogs. 286 tests.
- **PHPStan level 0 → 5 + publishing** — worked off the parked static-analysis
  backlog (ConfigCompiler match default, a ViewCompiler `@extends` docblock parse
  error, a variadic `@param`, a redundant loop condition, `self::` on a private
  method, a write-only property) and set `treatPhpDocTypesAsCertain: false` so the
  framework's deliberate boundary guards aren't read as dead; three stub/inference
  limitations carry sited `@phpstan-ignore` reasons, not blanket suppression. CI
  inherits level 5 (it runs `analyse -c phpstan.neon` with no `--level`). Resolved
  the parked monorepo-vs-split decision (docs/PUBLISHING.md): monorepo canonical,
  read-only `git subtree` splits to `codinglombok/*` feed Packagist under the
  `lombokclarion/*` vendor — `bin/split-package.sh` + `.github/workflows/split.yml`
  (verified against a local mirror). `.gitignore` completed for generated assets +
  upload dir. Still 286 tests, all four gates green.
- **Stage 15 — first upstream consumer fixes** — First real consumer
  application tested the framework on
  live work and reported omissions & hazards. Nine targeted fixes applied:
  (1) Middleware docblocks widened to support instance objects + `public/` entered
  PHPStan paths (U-S1-1); (2) index creation/drop API added (U-S1-4), countBy()
  for GROUP BY queries (U-S1-5); (3) `roleLazy()`/`permissionLazy()` break the
  boot-context trap (#41) via per-request resolution (U-S2-1); (4) TokenIssuer
  nonce added, 3-field → 4-field format with legacy compatibility (U-S2-3);
  (5) Request captures REMOTE_ADDR, RateLimit keyed ip-first (U-S2-2);
  (6) TokenStore contract documented (remember un-revokes); (7) phpstan-rules
  moved require→suggest (U-S1-2); (8) branch-alias 2.2.x-dev (U-S1-3);
  (9) local-dev runbook in DEPLOYMENT.md (U-S1-6). PhpStan L5 @docblock narrowed
  (F-15-11) to resolve `: callable` vs native `Closure`. **286 tests** (282 baseline
  + 4 new auth nonce/revoke/legacy tests; 5th case folded into existing malformed
  test), all four gates green, both autoload paths verified.
- **Relicense MIT → Apache-2.0 + release playbook** — `LICENSE` (Apache-2.0 full
  text), new `NOTICE`, and `"license": "Apache-2.0"` across all 19 `composer.json`
  + `package.json` via the idempotent `bin/relicense.sh` (reversible). Vendored
  `resources/` assets (LombokCSS, LombokCharts) stay MIT — recorded in `NOTICE`.
  `docs/RELEASE-PLAYBOOK.md` covers Packagist vs GitHub-Packages vs npm, the two
  release-history strategies (forward-only vs authentic staged-checkpoint replay
  via `bin/replay-checkpoints.sh`), Packagist registration, and the npm-badge
  reality (assets only). Still 286 tests, all four gates green.
- **Stage 16 (slice A) — migration tooling** — `migrate:status` (read-only
  ran/pending report per manifest entry, in manifest order, off the write side;
  `--strict` makes pending a failing exit code like `audit:security --strict`;
  applied-but-unmanifested migrations reported as orphans and always exit 1,
  since that is precisely what `MigrationRunner::rollback()` cannot run) and
  `make:migration ClassName [--table=NAME]` (stub always authors `down()`;
  target dir + namespace are constructor config, not discovery; class/table
  names validated at the boundary; never overwrites; **never edits
  `bootstrap/migrations.php`** — §2.5 keeps registration explicit and migration
  order is a decision a generator cannot make, so it prints the lines to paste).
  Verification of the cp-56 artifact itself surfaced F-16-01 (`.github/` absent
  from the delivery zip while four docs reference its workflows), F-16-02
  (reconciliation decision 5 offers `split.yml`, which is missing), and F-16-03
  (`ext-mbstring`/`ext-fileinfo` undeclared — 10 ValidationTest cases fail on a
  PHP build without mbstring). **300 tests** (286 + 14), gates 1–3 green;
  PHPStan unverified in that environment (F-16-04). See
  docs/audits/STAGE-16-migration-tooling.md.
- **Stage 17 (slice A) — error pages + logging** — new package
  `lombokclarion/log`: `LogLevel` (RFC 5424 enum), `Logger` + `LogsConveniently`,
  `LogRecord`, `ChannelLogger`, `LogHandler`/`LogFormatter` seams,
  Stream/InMemory handlers, Json/Line formatters, `NullLogger`. Context
  redaction runs inside the logger before any handler sees a record and has
  **no off switch** (F-17-04) — the leak shape is `log('failed', $request->all())`,
  not someone typing a password, so redaction that must be remembered is
  redaction that gets forgotten once, which is all it takes. Handlers are
  isolated from each other, since the moment a handler breaks is the moment the
  record matters most (F-17-05). Rotation is by date in the filename rather than
  by size, because size-based rotation renames files other FPM processes hold
  open (F-17-06).

  Error handling: `ErrorHandler`, `ErrorPageRenderer`, `ExceptionReporter`,
  `PlainErrorPageRenderer`, `ViewErrorPageRenderer`, and `errors/`
  templates for 404/403/500/generic. **`ErrorPageRenderer` takes a status code,
  not a Throwable** (F-17-08) — everything reaching the handler is still a 500,
  so no exception-class-to-status registry exists anywhere, which is the
  property `RendersResponse` was written to protect; a `RendersResponse`
  exception still renders itself and is not logged as a crash. `$debug` gates
  the data rather than the template (F-17-09), and `debugEnabled()` is
  asymmetric: `APP_DEBUG` can turn debug off locally but cannot turn it on
  outside a local environment, and an unset `APP_ENV` reads as production
  (F-17-10). The renderer ladder ends in a view-free renderer because the usual
  reason an error page fails is that the view layer is what broke (F-17-11).
  `Kernel`'s new `?ErrorHandler` defaults to null = the exact pre-Stage-17
  contract (F-17-13).

  An exhaustive dependency audit closed **F-16-11** (`ext-pdo` in `auth`, `bus`,
  `console`, `laravel-flavor`) and found the same bug twice more, both
  pre-existing: **F-17-01** (`view` imports `Http\{Middleware,Request,Response}`
  while declaring only `php`) and **F-17-02** (`console`→`bus`,
  `facades`→`bus`+`security`). All fixed; **F-17-15** records that no gate
  enforces this, which is why it keeps recurring.

  **377 tests** (333 + 24 + 20), gates 1–3 green with no new `audit:security`
  warnings; PHPStan still unverified here. See
  docs/audits/STAGE-17-errors-logging.md.
- **Stage 16 (slice B) — seeding framework** — `Seeder` interface,
  `SeederRunner`, `SeedContext`, deterministic `Factory`, an explicit ordered
  manifest at `bootstrap/seeders.php`, and three commands: `seed`,
  `seed:status`, `make:seeder`. Seeders are **tracked** in a `seeders` table
  rather than re-run every time, so run-once is structural and `seed` is safe
  beside `migrate` in a pipeline (F-16-13); each seeder's DML and its tracking
  row commit in **one transaction**, so "recorded as seeded" and "actually
  seeded" cannot disagree — which is why `Seeder`, unlike `Migration`, has no
  `runsInTransaction()` hook (F-16-14). `Factory` has no unseeded constructor
  and draws from an explicitly named `Mt19937` engine, and `seed` echoes the
  seed on every run so any run can be reproduced (F-16-15); generated addresses
  are fixed to the reserved `.invalid` TLD (F-16-16). `--force` requires
  `--only=ClassName`, so the destructive case is never the shortest thing to
  type (F-16-17), and a forced re-run at the same seed collides and rolls back
  rather than duplicating — with an explanation attached, since the driver only
  says "constraint violation" (F-16-18). `seed:status` mirrors `migrate:status`
  including `--strict` and orphan handling (F-16-19/20); `make:seeder` carries
  the same guarantees as `make:migration` and likewise never edits the manifest
  (F-16-21). **F-16-03 closed** (`ext-mbstring`/`ext-fileinfo` declared in root
  and validation, `composer.lock` re-synced). Inventorying that fix surfaced
  **F-16-11**: `auth`, `bus`, `console` and `laravel-flavor` use PDO without
  declaring `ext-pdo` — recorded, not applied. A contents audit of the packaged
  artifact then closed two more: **F-16-22** (`lombokclarion/auth` was declared
  only in `require-dev` while `app/` and `bootstrap/` import it directly —
  moved to `require`; note that the first write-up of this finding claimed
  `--no-dev` would fatal at boot, which testing disproved, since
  `laravel-flavor` pulls auth transitively) and **F-16-23** (the `framework`
  metapackage was the only one of 19 without a path repository entry).
  **333 tests** (300 + 33), gates 1–3 green with no new `audit:security`
  warnings; PHPStan still unverified here.
