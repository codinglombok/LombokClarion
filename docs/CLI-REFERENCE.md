# CLI Reference — LombokClarion Commands

All commands are invoked via `php bin/lombokclarion <command> [options]`.

---

## migrate

**Signature:** `migrate [--connection=NAME]`

Applies all pending migrations in the order specified by the migration manifest.
Each connection maintains its own `migrations` table, so schema history is per-database.

**Options:**
- `--connection=NAME` — Run migrations on the named connection (default: `default`). Useful for multi-database setups (e.g., `--connection=reporting`).

**Example:**
```bash
php bin/lombokclarion migrate                      # Run on default connection
php bin/lombokclarion migrate --connection=reporting  # Run on reporting connection
```

**Note:** Migrations are applied under a **separate, higher-privileged database role**
than the application runtime (see deployment guide for least-privilege role setup).
That role separation is a deployment-config concern, not enforced by this command.

---

## migrate:rollback

**Signature:** `migrate:rollback [--steps=N] [--connection=NAME]`

Rolls back previously applied migrations in reverse order (last-applied first).

**Options:**
- `--steps=N` — Rollback the last N migration batches (default: 1 batch).
- `--connection=NAME` — Target connection (default: `default`).

**Example:**
```bash
php bin/lombokclarion migrate:rollback                          # Rollback 1 batch
php bin/lombokclarion migrate:rollback --steps=3               # Rollback 3 batches
php bin/lombokclarion migrate:rollback --connection=reporting  # Rollback on reporting
```

**Prerequisites:** Every migration must implement `down()` (enforced at boot).
Backup your database before rolling back in production.

---

## migrate:status

**Signature:** `migrate:status [--connection=NAME] [--strict]`

Read-only report of which manifest migrations have run and which have not, in
manifest order. Applies nothing and rolls nothing back. Reads the **write**
side of the connection: a replica would answer about a lagging copy, which is
the wrong answer to "is this database current?".

**Options:**
- `--connection=NAME` — Target connection (default: `default`).
- `--strict` — Pending migrations become exit 1. Same convention as `audit:security --strict`.

**Exit codes:**
- `0` — Everything in the manifest has run (or some are pending without `--strict`).
- `1` — Pending with `--strict`, **or** an orphan was found, **or** a bad argument.

**Orphans:** a migration recorded as applied but absent from the manifest is
reported on STDERR and **always** exits 1, with or without `--strict` — that is
exactly the state `migrate:rollback` cannot run.

```bash
php bin/lombokclarion migrate:status
php bin/lombokclarion migrate:status --strict            # deploy gate
php bin/lombokclarion migrate:status --connection=reporting
```

---

## make:migration

**Signature:** `make:migration ClassName [--table=NAME]`

Writes a migration class file from a stub. The stub **always** authors `down()`.

**Options:**
- `--table=NAME` — Generate `createTable`/`dropTable` bodies for that table. Must be a bare identifier (lowercase, digits, underscores).

**Constraints:**
- Class name must match `^[A-Z][A-Za-z0-9]*$` — no separators, so the declared namespace and the file's location cannot disagree and no path escape is expressible.
- Never overwrites an existing file.
- The target directory must already exist (it is not created silently).

**It does not edit `bootstrap/migrations.php`.** Registration is explicit and
migration *order* is a decision a generator cannot make, so the command prints
the `use` line and the `::class,` line for you to paste.

```bash
php bin/lombokclarion make:migration CreateInvoicesTable
php bin/lombokclarion make:migration CreateInvoicesTable --table=invoices
```

---

## seed

**Signature:** `seed [--connection=NAME] [--only=Class] [--force] [--seed=N]`

Runs the seeder manifest (`bootstrap/seeders.php`). **Pending-only by default**
— seeders are tracked in a `seeders` table exactly as migrations are, so a
second `seed` is a no-op rather than a silent row duplication. Safe to put
beside `migrate` in a deploy pipeline.

Each seeder's inserts and its tracking row commit in **one transaction**, so a
seeder that fails halfway leaves neither rows nor a record.

**Options:**
- `--connection=NAME` — Target connection (default: `default`). Uses the write side.
- `--only=Class` — Run just this seeder. Accepts the short class name or the FQCN. Repeatable.
- `--force` — Re-run even if already recorded. **Requires `--only=`** (see below).
- `--seed=N` — Integer seed for the run's `Factory` (default: configured `defaultSeed`).

**Why `--force` requires `--only=`:** re-running duplicates rows unless the
seeder is idempotent, and nothing can promise that it is. Naming the seeder
makes the operator state which duplication they are choosing; a bare
`seed --force` would make the destructive case the shortest thing to type.

**Reproducibility:** the seed is echoed on **every** run, passed or not — a run
whose seed was never printed cannot be reproduced. Passing the same `--seed=N`
regenerates byte-identical data.

**A forced re-run at the same seed is expected to fail:** identical values
collide on any unique column and the transaction rolls back, so nothing is
duplicated. The command explains this when it happens. Use a different
`--seed=N` to add a second distinct set of rows.

```bash
php bin/lombokclarion seed
php bin/lombokclarion seed --seed=12345                        # reproduce a prior run
php bin/lombokclarion seed --only=DemoWidgetsSeeder
php bin/lombokclarion seed --only=DemoWidgetsSeeder --force --seed=999
```

---

## seed:status

**Signature:** `seed:status [--connection=NAME] [--strict]`

Read-only report of which seeders have run. Seeds nothing. Deliberately the
same shape as `migrate:status`, including `--strict` and the orphan rule.

**Options:**
- `--connection=NAME` — Target connection (default: `default`).
- `--strict` — Pending seeders become exit 1.

**Orphans:** a recorded seeder absent from the manifest exits 1 with or without
`--strict`, but for a different reason than a migration orphan. A migration
orphan blocks rollback; a seeder orphan blocks nothing, because data has no
`down()`. It is an error because it is the only signal that rows exist which no
manifest entry accounts for. Clear the record — without touching the rows —
with `SeederRunner::forget()`.

```bash
php bin/lombokclarion seed:status
php bin/lombokclarion seed:status --strict
```

---

## make:seeder

**Signature:** `make:seeder ClassName [--table=NAME]`

Writes a seeder class file from a stub. Same guarantees as `make:migration`:
validated class name, bare-identifier `--table`, never overwrites, target
directory must exist, and **it does not edit `bootstrap/seeders.php`** —
seeder order matters the same way migration order does (rows referencing a
parent table must be seeded after it), so it prints the lines to paste.

```bash
php bin/lombokclarion make:seeder DemoWidgetsSeeder
php bin/lombokclarion make:seeder DemoWidgetsSeeder --table=widgets
```

---

## user:create

**Signature:** `LOMBOKCLARION_PASSWORD='...' lombokclarion user:create <email>`

Creates an application user with a given email and password. Used to bootstrap
the first admin account.

**Arguments:**
- `<email>` — Email address of the new user.

**Environment Variables:**
- `LOMBOKCLARION_PASSWORD` — Password to assign. **Required** — if not set or
  empty, the command fails with an error.

**Example:**
```bash
LOMBOKCLARION_PASSWORD='MySecurePassword123!' php bin/lombokclarion user:create admin@example.test
```

**Security Notes:**
- The password is passed via an environment variable to avoid exposing it in
  process lists. Always use strong, unique passwords.
- Use `~/.env.local` or a secrets manager in production, never commit passwords
  to version control.

---

## audit:security

**Signature:** `audit:security [--public=METHOD:/path] [--strict]`

Validates security configuration and detects common misconfigurations:

- ✗ Missing CSRF middleware on POST/PUT/DELETE routes
- ✗ `APP_DEBUG=true` in a production `.env` file
- ✗ Missing `SecurityHeaders` middleware in global middleware stack
- ⚠ (Warning) Mutating routes without `Authenticate` middleware

**Options:**
- `--public=METHOD:/path` — Declare a mutating route as intentionally
  unauthenticated (e.g., `--public=POST:/login`). Can be used multiple times.
- `--strict` — Treat warnings (unauthenticated mutating routes) as failures
  (exit 1). Useful for codebases that have triaged all warnings.

**Exit codes:**
- `0` — No findings (or warnings only, if `--strict` not set)
- `1` — Finding detected, or warning with `--strict`

**Example:**
```bash
# Check with known public routes
php bin/lombokclarion audit:security \
  --public=POST:/login \
  --public=POST:/register \
  --public=POST:/api/webhooks

# Strict mode: fail on any warning
php bin/lombokclarion audit:security --public=POST:/login --strict
```

**Rationale for warnings vs. findings:**
- Missing CSRF or debug=true are always errors: they break security invariants.
- Unauthenticated mutating routes are often correct (registration, login,
  webhooks), so treating them as errors would make every real app start red.
  Warnings make intent visible in diffs: `--public=...` is a deliberate choice,
  not an ignore.

---

## audit:sql

**Signature:** `audit:sql [app|packages|both] [--exclude=PATH] [--explain]`

Scans PHP code for SQL injection vulnerabilities via tokenizer-based static analysis.
Detects variable interpolation in SQL strings and validates against the
`TrustedDdl`-approved query builder or explicit `TrustedDdl::mark()` calls.

**Arguments:**
- `app` — Scan only `app/` (default: app + packages)
- `packages` — Scan only `packages/`
- `both` — Explicitly scan both (same as default)

**Options:**
- `--exclude=PATH` — Exclude a file or directory (e.g.,
  `--exclude=packages/persistence/src/QueryBuilder.php` to skip that file,
  which has its own internal SQL generation logic).
- `--explain` — Show full SQL context around each finding.

**Exit codes:**
- `0` — No issues found
- `1` — Vulnerabilities detected

**Example:**
```bash
php bin/lombokclarion audit:sql app               # Scan app/ only
php bin/lombokclarion audit:sql packages          # Scan packages/ only
php bin/lombokclarion audit:sql app packages \    # Both, exclude QueryBuilder
  --exclude=packages/persistence/src/QueryBuilder.php
php bin/lombokclarion audit:sql app --explain    # Show context
```

**How it works:**
- All parameterized QueryBuilder queries (bound params via `?`) are safe.
- Raw `TrustedDdl::mark($sql)` calls are trusted by explicit review.
- Bare string interpolation (e.g., `"SELECT * FROM $table"`) is flagged.
- Comments in code are scanned too; mark them with `// @trustedDdl` if needed.

---

## optimize

**Signature:** `optimize`

Pre-compiles framework artifacts for production deployments, eliminating
reflection and parsing on every request:

- `storage/services.compiled.php` — Container and service definitions
- `storage/config.compiled.php` — All configuration values
- `storage/routes.compiled.php` — Compiled route matcher (O(1) lookup)
- `storage/assets.manifest.php` — Asset hash manifest
- Published assets in `public/assets/` — Hashed CSS/JS with cache-busting

**Exit codes:**
- `0` — Compilation successful

**Example:**
```bash
php bin/lombokclarion optimize
```

**When to run:**
- After `composer install` (dependency changes)
- Before deployment to production
- In CI as part of the build step
- After any `.env` or config file changes

**Artifacts are gitignored** — regenerate them on every deploy. The compiled
files are deployment-specific (they embed absolute paths) and cannot be safely
shared across machines.

---

## work

**Signature:** `work`

Starts a long-running daemon that processes queued jobs from the queue backend
(Redis, database, in-memory, etc.). Runs until interrupted (Ctrl+C, SIGTERM).

**Exit codes:**
- `0` — Graceful shutdown
- Non-zero — Unhandled exception (logs will show details)

**Example:**
```bash
php bin/lombokclarion work
```

**In deployment:**
- Systemd service or Docker container entry point
- Should be supervised (respawn on crash)
- Catches SIGTERM for graceful shutdown (drain queue, exit)
- Logs job processing to stderr/structured logs

**See also:** `docs/DEPLOYMENT.md` for systemd/Docker/PM2 examples.

---

## check-domain-boundary

**Signature:** `php bin/check-domain-boundary.php` (not via `lombokclarion`)

Verifies that application domain code (`app/Domain/`) does not import from the
framework (`LombokClarion\*` namespace). This enforces a clean separation: domain
logic should be framework-agnostic.

**Exit codes:**
- `0` — No domain imports found (boundary clean)
- `1` — Framework imports detected in domain code

**Example:**
```bash
php bin/check-domain-boundary.php
```

**Why it matters:**
- Domain code is reusable, testable, and independent of the HTTP/console framework.
- An import `LombokClarion\*` in `app/Domain/` indicates the boundary has leaked.
- This is a design gate, not just a lint rule.

---

## Combining commands in CI

A typical CI pipeline runs all gates together:

```bash
# 1. Run unit/integration tests
php tests/run-all.php

# 2. Check domain boundary
php bin/check-domain-boundary.php

# 3. Security & SQL audits
php bin/lombokclarion audit:security \
  --public=POST:/login --public=POST:/logout \
  --public=POST:/widgets --strict
php bin/lombokclarion audit:sql app packages \
  --exclude=packages/persistence/src/QueryBuilder.php

# 4. Static analysis (PHPStan)
phpstan analyse -c phpstan.neon --no-progress

# 5. Deploy gates — is the target database current, in schema and in data?
#    Run these against the deploy target, not the CI scratch database.
php bin/lombokclarion migrate:status --strict
php bin/lombokclarion seed:status --strict
```

Both `--strict` checks exit 1 on anything pending, and exit 1 on an orphan
regardless of `--strict`.

**Caveat on `.github/workflows/ci.yml`:** that file is referenced throughout
these docs but is **absent from the current delivery artifact** — see F-16-01
in `docs/audits/STAGE-16-migration-tooling.md`. Restore it from the canonical
repository before treating any "CI green" claim as checked.

---

## Environment variables

Commands respect these settings:

| Variable | Used By | Purpose |
|----------|---------|---------|
| `LOMBOKCLARION_PASSWORD` | `user:create` | Plaintext password for new user |
| `APP_ENV` | All | Affects migration behavior, config loading, debug output |
| `APP_KEY` | Security | HMAC secret for CSRF, session tokens, encryption |
| `DB_*` | `migrate`, `migrate:rollback` | Database connection details |

See `.env.example` for all available variables.

---

## Tips & troubleshooting

**"Migration not found"**
- The manifest lists migrations by class name, not by filename.
- Ensure your migration class is listed in `config/migrations.php`.

**"Connection unknown"**
- Double-check the name in `config/database.php`.
- Use `--connection=default` (the default) if unsure.

**"audit:security warns but exits 0"**
- Warnings (unauthenticated mutating routes) don't fail the build by default.
- Use `--strict` to fail on warnings, or reduce warnings with `--public=...`.

**"optimize says database.sqlite doesn't exist"**
- Run `php bin/lombokclarion migrate` first to create the database.
- The schema must exist before compilation can read it.

**"work processes nothing"**
- Check that jobs are actually being queued (check your queue driver config).
- Enable logging to see what the worker is doing.
- Verify the connection to Redis/database (if using those backends).
