# Audit findings — Stage 15 (upstream fixes from the first production consumer)

One file per stage under `docs/audits/`. Each session appends its findings here
so the development process keeps a running, reviewable audit log. Same fixed
format as STAGE-14 — copy it for `STAGE-16-*.md`, etc.

- **State at audit:** 19 packages, **286 tests** (282 baseline + 4 new AuthTest
  tests; a fifth planned case — non-hex nonce — was folded into the existing
  *malformed tokens* test instead of becoming its own test), four gates green,
  PHPStan level 5, `public/` now inside the PHPStan paths.
- **Scope this stage:** apply and verify the findings reported by the first real
  production consumer (Stage 0–2, on top of cp-54). No new feature intent of
  its own — every change is an upstream fix or an API the consumer proved
  missing. Source ids: `U-S1-1..6`, `U-S2-1..3`.
- **Finding id:** `F-15-NN`. **Severity:** Critical / High / Medium / Low / Info.
  **Status:** Fixed / Accepted (won't change, with reason) / Deferred.

---

## A. API gaps proven by the consumer (persistence & routing)

| ID | Sev | Package (origin stage) | Finding | Resolution | Status |
|------|--------|------------------------------|---------|------------|--------|
| F-15-01 | Medium | routing / Router+Kernel (S1) | Middleware docblocks said `list<class-string>` while instance `Middleware` objects were equally supported — PHPStan-clean consumer code was impossible against the declared types; `public/index.php` was outside PHPStan paths so the framework never analyzed its own entrypoint | U-S1-1: docblocks widened to `list<class-string\|Middleware>` on every verb + `addRoute()` + `group()` + `$groupMiddleware` + the Kernel ctor; `public/` added to `phpstan.neon` paths | Fixed |
| F-15-02 | Medium | persistence / SchemaBuilder (S3) | No way to create or drop an index without hand-writing DDL through `TrustedDdl` — the consumer needed lookup indexes and reversibility | U-S1-4: `createIndex($table, $columns, $unique=false)` + `dropIndex()`; index name **derived** (`<table>_<cols>_idx`, not caller-supplied — smaller validation surface, `dropIndex` reversible without remembering a name); `Identifier::validate` on every part, `TrustedDdl::mark` on the statement, driver-aware DROP (mysql/sqlite) | Fixed |
| F-15-03 | Low | persistence / QueryBuilder (S3) | Counting per group required raw SQL; only scalar `count()` existed | U-S1-5: `countBy(string $col): array<string,int>` — GROUP BY column audited through `qualifyColumn`, `buildSelect` gains a `$countByColumn` param | Fixed |

## B. Auth & request hardening

| ID | Sev | Package (origin stage) | Finding | Resolution | Status |
|------|--------|------------------------------|---------|------------|--------|
| F-15-04 | High | auth / Authorize (S9) | The eager `role()`/`permission()` factories re-arm the #41 boot-context trap: a routes-file evaluated at boot captures a boot-time `AuthManager`, whose per-request context never fills → 401 with a valid token, in every long-running runtime | U-S2-1: `roleLazy()`/`permissionLazy()` — instance carries only the required string plus a resolver callable returning `array{AuthManager, RoleRepository}`, resolved inside `handle()` per request, **no memoization by design** (caching the pair on the instance would recreate the trap); resolver-callable form chosen over `ContainerInterface` so packages/auth gains no container dependency; class docblock documents the trap, eager factories carry a warning | Fixed |
| F-15-05 | High | auth / TokenIssuer (S9) | `issue()` was deterministic per (user, second): two logins in one second produced byte-identical tokens ⇒ identical tokenIds ⇒ UNIQUE explosion or a resurrected just-revoked row in any durable TokenStore | U-S2-3: 8-byte hex nonce signed into the payload — format is now 4-field `id.expiry.nonce.sig`; `verify()` still accepts the legacy 3-field format until those tokens expire (payload-version compatibility, so live sessions survive the deploy); non-hex nonce rejected | Fixed |
| F-15-06 | Medium | auth / TokenStore (S9) | `remember()` had no written contract for a re-remembered tokenId — a durable implementation could legitimately leave `revoked_at` set and lock a user out | U-S2-3: contract documented — `remember()` is a full overwrite **and un-revoke** (reference: UPSERT with `revoked_at = NULL`); pinned by a remember→revoke→remember test against the reference `InMemoryTokenStore` | Fixed |
| F-15-07 | Medium | http / Request + security / RateLimit (S1/S6) | `Request` never captured `REMOTE_ADDR`, so `RateLimit` keyed on the spoofable `X-Forwarded-For` header first | U-S2-2: `Request` ctor gains trailing `?string $remoteAddr = null` (non-breaking), `fromGlobals()` captures `REMOTE_ADDR`, new `ip(): ?string` (XFF deliberately **not** consulted — docblock says so), `withAttributes()` forwards it; RateLimit identity → `ip() ?? xff ?? 'unknown'` (XFF kept only as fallback for proxied setups without REMOTE_ADDR) | Fixed |

## C. Packaging & metadata

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-15-08 | Medium | phpstan-rules | `phpstan/phpstan` sat in `require` — installing the rules package dragged Packagist into an otherwise fully local dependency graph, contradicting the CI PHAR flow | U-S1-2: moved to `suggest` with the PHAR flow documented there; `composer.lock` refreshed, install resolves | Fixed |
| F-15-09 | Low | all 19 composer.json | No branch-alias: `dev-main` was unversioned for consumers tracking main | U-S1-3: `extra.branch-alias {dev-main: 2.2.x-dev}` in all 19 packages (last history tag = v2.1.0) | Fixed |
| F-15-10 | Info | docs / DEPLOYMENT | No local-dev runbook: first-run devs hit the #44 APP_KEY fail-safe with no guidance | U-S1-6: "Local dev server" section (fail-safe explanation + dev invocation) in `docs/DEPLOYMENT.md` + `.env.example` | Fixed |

## D. Findings from this verification session

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-15-11 | Low | auth / Authorize | PHPStan (with `public/` newly in paths and L5): ctor docblock declared the resolver as `(callable(): …)\|null` against the native `?\Closure` — 2 `phpDocType` errors, exactly the class of drift U-S1-1 exists to catch | Docblock narrowed to `(\Closure(): array{AuthManager, RoleRepository})\|null` (factories still accept `callable` and normalize via `\Closure::fromCallable`) | Fixed |
| F-15-12 | Info | tests / AuthTest | Handoff doc promised +5 tests (target 287); delivered as 4 new tests + 1 extra case (`dXNlcg.1000.NOTHEX.sig`) inside the existing *malformed tokens* test | Accepted — coverage identical, test count is **286**; docs/README numbers updated to 286, not 287 | Accepted |

## E. Open / deferred (carried forward from STAGE-14)

| ID | Sev | Finding | Owner / next step |
|------|--------|---------|-------------------|
| F-14-15 | Medium | npm ↔ framework license mismatch | Re-publish npm `lombokclarion` as Apache-2.0, or annotate it as asset-tooling-only |
| F-14-16 | High | GitHub `main` far behind local | Push the staged history (`PUSH-INSTRUCTIONS.md`) before anyone forks — history now needs a v2.2.x tip commit for cp-56 |
| F-14-17 | Medium | `v.1.0.0` tag malformed | Retag `v1.0.0`; delete the old remote tag |
| F-14-18 | Low | PHPStan level 6 backlog | Iterable value-type annotations across the codebase — its own stage |
| F-14-19 | Low | Stage 9 (auth, 186 tests) snapshot absent | History jumps 124→214 at v1.1.0; optional to split if a stage-9 snapshot resurfaces |
| F-14-20 | Info | Packagist not yet live | Run `release-kit/create-mirrors.sh` → submit 19 URLs (`release-kit/PACKAGIST-SUBMIT.md`) |

---

## Verification record (this session)

- Suite: **286/286 PASS** under the `autoload.php` shim **and** under a real
  Composer autoload (shim temporarily replaced by a `vendor/autoload.php`
  passthrough, then restored).
- Gates (canonical flags from `.github/workflows/ci.yml`): domain-boundary OK;
  `audit:security --strict` (+4 `--public`) clean; `audit:sql app packages
  --exclude=packages/persistence/src/QueryBuilder.php --explain` clean;
  PHPStan PHAR 2.2.5, level 5, `public/` included — `[OK] No errors` (after
  F-15-11).
- `composer validate` OK (pre-existing `*` constraint warnings only, see
  F-14-11); artifacts rebuilt via `migrate` + `optimize` before the suite.

## Convention for next sessions

1. Copy this file's shape to `docs/audits/STAGE-<N>-<slug>.md`.
2. Keep the `F-<N>-NN | Sev | Area | Finding | Resolution | Status` table format.
3. Every Deferred/Accepted item states a reason; every Fixed item is verified
   (suite + gates green) before it's marked Fixed.
4. Carry unresolved items forward into the new stage's "Open / deferred" table
   until they're closed, so nothing is silently dropped.
