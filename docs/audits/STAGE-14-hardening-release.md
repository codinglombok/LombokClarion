# Audit findings — Stage 14 (static-analysis hardening & release engineering)

One file per stage under `docs/audits/`. Each session appends its findings here
so the development process keeps a running, reviewable audit log. Format is fixed
so future stages stay comparable — copy it for `STAGE-15-*.md`, etc.

- **State at audit:** 19 packages, 282 tests, four gates green, PHPStan level 5.
- **Scope this stage:** raise the static-analysis bar, prepare publishing, change
  license, add the metapackage. No feature work.
- **Finding id:** `F-14-NN`. **Severity:** Critical / High / Medium / Low / Info.
  **Status:** Fixed / Accepted (won't change, with reason) / Deferred (needs a
  later action, often outside the sandbox).

---

## A. Static analysis (PHPStan level 0 → 5)

Raising the level surfaced a real backlog. Genuine defects were fixed; analyzer
limitations were quarantined with *sited* `@phpstan-ignore` + reason, never a
blanket mute. `treatPhpDocTypesAsCertain: false` was set as a boundary-validation
stance (PHPDoc is a promise a caller can break), which alone cut findings 23 → 10.

| ID | Sev | Package (origin stage) | Finding | Resolution | Status |
|------|--------|------------------------------|---------|------------|--------|
| F-14-01 | Medium | config / ConfigCompiler (S1) | `cast()` match had no default arm — an unknown declared type was silently unhandled | Added `default => throw InvalidArgumentException` (hard error, explicit-over-magic) | Fixed |
| F-14-02 | Low | view / ViewCompiler (S1) | Docblock listed `@extends` as a template directive; PHPStan parsed it as the generics tag → phpDoc parse error | Reworded the directive list so no name starts with `@` | Fixed |
| F-14-03 | Low | persistence / QueryBuilder (S1) | `@param list<string>` on variadic `...$relations` (element type is `string`, not the list) | Changed to `@param string ...$relations` | Fixed |
| F-14-04 | Low | console / TokenScanner (S5) | `while ($i < $count && $depth > 0)` — the `$depth > 0` clause is dead (a `break` on `$depth <= 0` guards it) | Dropped the redundant clause | Fixed |
| F-14-05 | Low | active-record / Model (S4) | `static::filterFillable()` called on a **private** method (unsafe LSB target) | Changed to `self::` (identical resolution, safe) | Fixed |
| F-14-06 | Low | active-record / Model (S4) | `$exists` property was write-only (3 writes in create/delete/hydrate, 0 reads) | Removed the property and all writes (behaviour-neutral) | Fixed |
| F-14-07 | Info | active-record / Model (S4) | `find()` returns `static` but PHPStan loses it through the `self`-returning fluent chain | Sited `@phpstan-ignore` + inline `@var static\|null` (analyzer limitation, code correct) | Accepted |
| F-14-08 | Info | console / WorkCommand (S1) | Worker daemon `while (true)` flagged `while.alwaysTrue` | Sited ignore; loop is intentionally unbounded (exits on signal) | Accepted |
| F-14-09 | Info | container / Container (S1) | `catch` around `new ReflectionClass($class)` flagged dead by the stub | Sited ignore; defensive against a lying class-string / autoload failure at runtime | Accepted |

Note: the pre-existing `QueryBuilder rawSqlValue` ignore is unchanged and still
tests-backed. **Level 6 not taken** — it is a large iterable-annotation project,
not a correctness backlog (tracked as F-14-18).

## B. Publishing readiness

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-14-10 | Medium | .gitignore | Generated artifacts weren't ignored: hashed `public/assets/*`, `storage/assets.manifest.php`, `storage/app/*` uploads — risk of committing stale/derived files | Added ignores (following the existing `*.compiled.php` pattern) + `.gitkeep`s; verified with `git check-ignore` | Fixed |
| F-14-11 | Info | dependency graph | All inter-package constraints are `*` | Accepted — fine for a pre-1.0 line and makes the Packagist split need no rewrite | Accepted |
| F-14-12 | Low | DX / install | No one-require install path; consumers had to name packages individually | Added `lombokclarion/framework` metapackage (16 runtime requires; `testing`/`phpstan-rules` as `suggest`) | Fixed |
| F-14-13 | Info | split tooling | Split mechanism needed to stay inspectable (repo keeps its moving parts local) | `bin/split-package.sh` (pure git-subtree, verified against a local bare mirror) + `.github/workflows/split.yml` (explicit 19-package matrix, CI-gated, tag-aware) | Fixed |

## C. License & registry

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-14-14 | Info | LICENSE | User chose to move MIT → Apache-2.0 | Applied to LICENSE + NOTICE + all 19 `composer.json` + `package.json` via `bin/relicense.sh` (reversible). Vendored `resources/` assets stay MIT | Fixed |
| F-14-15 | Medium | npm vs LICENSE | npm `lombokclarion` published as **MIT** while the framework is now **Apache-2.0** — badge/LICENSE disagreement | Documented in `docs/BADGES.md`; needs a re-publish or an explicit "asset-tooling is MIT" note | **Deferred** (user) |
| F-14-16 | High | published repo | `main` on GitHub is a stale ~Stage 5/6 snapshot (124 tests, 12–13 pkg) vs local 282 tests / 19 pkg | Built a staged git history (v1.0.0→v2.1.0) to reconcile via a one-time force-push; see `PUSH-INSTRUCTIONS.md` | **Deferred** (user push) |
| F-14-17 | Medium | release tag | Release tag `v.1.0.0` is malformed (extra dot, non-SemVer); Packagist derives versions from tags | Delete + retag `v1.0.0` (documented in playbook) | **Deferred** (user) |

## D. Open / deferred (carry forward to future stages)

| ID | Sev | Finding | Owner / next step |
|------|--------|---------|-------------------|
| F-14-15 | Medium | npm ↔ framework license mismatch | Re-publish npm `lombokclarion` as Apache-2.0, or annotate it as asset-tooling-only |
| F-14-16 | High | GitHub `main` far behind local | Push the staged history (`PUSH-INSTRUCTIONS.md`) before anyone forks |
| F-14-17 | Medium | `v.1.0.0` tag malformed | Retag `v1.0.0`; delete the old remote tag |
| F-14-18 | Low | PHPStan level 6 backlog | Iterable value-type annotations across the codebase — its own stage |
| F-14-19 | Low | Stage 9 (auth, 186 tests) snapshot absent | History jumps 124→214 at v1.1.0 (auth+multi-db combined); optional to split if a stage-9 snapshot resurfaces |
| F-14-20 | Info | Packagist not yet live | Run `release-kit/create-mirrors.sh` → submit 19 URLs (`release-kit/PACKAGIST-SUBMIT.md`) |

---

## Convention for next sessions

1. Copy this file to `docs/audits/STAGE-<N>-<slug>.md`.
2. Keep the same section shape (A/B/C/D or task-appropriate groups) and the
   `F-<N>-NN | Sev | Area | Finding | Resolution | Status` table format.
3. Every Deferred/Accepted item states a reason; every Fixed item is verified
   (suite + gates green) before it's marked Fixed.
4. Carry unresolved items forward into the new stage's "Open / deferred" table
   until they're closed, so nothing is silently dropped.
