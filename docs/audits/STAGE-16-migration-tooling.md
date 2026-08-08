# Audit findings — Stage 16 (migration tooling, seeding, delivery verification)

One file per stage under `docs/audits/`. Same fixed format as STAGE-14/15.
The filename slug is historical — it was written when slice A was the whole
stage; slice B (seeding) is groups E/F below rather than a second file,
because the convention is one file per stage, not one per slice.

- **State at audit:** 19 packages, **333 tests** (286 baseline + 14 slice A
  `MigrationToolingTest` + 33 slice B `SeederToolingTest`),
  `audit:sql`/`audit:security`/`check-domain-boundary` green — all three
  re-run after slice B, with no new `audit:security` warnings (the same 4
  intentional public routes). PHPStan **not run this session** — see F-16-04.
- **Scope this stage:** slice A (migration visibility and scaffolding), slice B
  (seeding framework), plus an independent re-verification of the cp-56
  delivery artifact rather than a restatement of its handoff docs.
- **Finding id:** `F-16-NN`. **Severity:** Critical / High / Medium / Low / Info.
  **Status:** Fixed / Accepted (won't change, with reason) / Deferred.

---

## A. Delivery-artifact gaps (found by verifying, not by reading)

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-16-01 | High | delivery / `.github/` | `LombokClarion-Stage15-Final.zip` ships **no `.github/` directory at all** — no `ci.yml`, no `split.yml`, no `npm-publish.yml`, no `pages.yml` — while `docs/CLI-REFERENCE.md`, `docs/DEPLOYMENT.md`, `docs/PROJECT-SUMMARY.md` and `docs/AUDIT-TRAIL.md` all reference them as shipped artifacts. Not a hidden-file exclusion artifact: `.gitignore`, `.gitattributes`, `.editorconfig`, `.dockerignore` and `.env.example` are all present in the same zip | Recorded. The workflows must be restored from the canonical repo (or re-authored) before the "all 4 CI gates green" claim can be checked by anyone holding only this zip | Deferred (needs the canonical repo) |
| F-16-02 | High | reconciliation plan | `RECONCILIATION-STRATEGY.md` §8 decision 5 offers *"Use `.github/workflows/split.yml` (automation) or manual `git subtree`"* — but per F-16-01 that file is not in the delivery. The decision as written cannot be answered "automation" from this artifact alone. `bin/split-package.sh` **is** present, so the manual path is intact | Decision 5 should be re-framed: the real choice is *restore/author split.yml* vs *drive `bin/split-package.sh` by hand* | Deferred (user decision, Session-N+1) |
| F-16-03 | Medium | composer.json / ext | Root `composer.json` declares only `ext-pdo` and `ext-openssl`, but `packages/validation/src/Rule.php:231` calls `mb_strlen()` and `Rule::file()` sniffs mime via `finfo`. On a PHP build without `mbstring`, **10 ValidationTest cases fail** with `Call to undefined function` — reproduced on a clean PHP 8.3.6 (`php-cli` only), fixed by installing `php-mbstring`. The suite's own green status silently depended on the developer's PHP build | `ext-mbstring` and `ext-fileinfo` added to root `composer.json` **and** `packages/validation/composer.json`. `composer.lock` re-synced with `composer update --lock` (every dependency is a `path` repository, so this needed no Packagist) — its `platform` block now carries all four extensions, and `composer validate` is clean on both files. Suite re-run: unchanged | Fixed |
| F-16-04 | Info | gates | Gate 4 (PHPStan level 5) **could not be run**: `phpstan.neon` ships, but PHPStan itself installs from Packagist, which is outside this environment's allowed network. Gates 1–3 were run and are green; gate 4 is unverified here, not failing | State it as unverified rather than inheriting the claim | Accepted (environment limit) |

### Note on the verified baseline

The 286-test / gates-green claim in the handoff docs **holds** — independently
re-run this session, once `mbstring` was present (F-16-03) and after
`migrate` + `optimize`, which the suite requires: without the compiled
artifacts, `CompiledBootParityTest` fails 2 cases with a message naming the
fix. That ordering is correct behaviour, but it means a bare
`php tests/run-all.php` on a fresh unzip does not pass, and
`PRE-SESSION-CHECKLIST.md` step 5 lists the commands in the right order —
worth keeping in that order.

## B. Stage 16 feature work — `migrate:status`

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-16-05 | Medium | console (S1/S10) | Whether a database was current could only be learned by running `migrate` — the question could not be asked without also answering it, so no deploy gate could check it | New `MigrateStatusCommand` (`migrate:status`): read-only report of ran/pending per manifest entry, in manifest order. Takes the `ConnectionManager` (not a runner) for the same reason `MigrateCommand` does — `--connection=` only means something if the connection is still open to choose at `run()` time. Reads the **write** side: a replica answers about a lagging copy, which is the wrong answer to "is this current?" | Fixed |
| F-16-06 | Medium | console / persistence | An applied migration deleted from the manifest is the exact state `MigrationRunner::rollback()` hard-errors on (#45's neighbour), and nothing surfaced it until someone attempted a rollback | `migrate:status` reports orphans separately on STDERR and exits 1 **with or without `--strict`** — an unrunnable rollback is not a warning | Fixed |
| F-16-07 | Low | console | No CI-usable "is the schema current" check | `--strict` turns pending migrations into exit 1, matching the existing `audit:security --strict` convention. Unknown flags and unknown connection names are hard errors listing the defined connections — same reasoning as `MigrateCommand`: a typo'd `--connnection=` that silently reported on the primary is a bad afternoon | Fixed |

## C. Stage 16 feature work — `make:migration`

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-16-08 | Low | console | Writing a migration meant hand-copying an existing one, including remembering to author `down()` — the omission #45 was about | New `MakeMigrationCommand` (`make:migration ClassName [--table=NAME]`). Generated file always authors `down()`; without `--table` it carries a TODO stating that a no-op `down()` cannot be rolled back, and the command warns on STDOUT that the stub is incomplete. Generated output is asserted to parse (`php -l`) by its test | Fixed |
| F-16-09 | Medium | console | **Design decision, recorded so it is not "fixed" later by mistake:** the generator does **not** edit `bootstrap/migrations.php`. §2.5 makes registration explicit, and migration *order* is a decision (an `invoices` table referencing `customers` must run after it) that a generator cannot make. Silently appending would reintroduce the "where did this come from?" property the manifest exists to prevent | The command prints the `use` line and the `::class,` line to paste, plus *"order matters"*. Pinned by a test asserting the manifest is never touched | Accepted (deliberate) |
| F-16-10 | Medium | console | Generator input reaches both the filesystem and generated SQL | Class name must match `^[A-Z][A-Za-z0-9]*$` — separators and `..` rejected, so the declared namespace and the file location cannot disagree and no path escape is possible; `--table=` must be a bare identifier (`things; DROP TABLE users` rejected). Target directory and namespace are **constructor arguments**, not runtime discovery: the command has no opinion about where an app keeps migrations and no way to guess wrong. A non-existent target directory is an error, not a directory to create silently. Existing files are never overwritten — clobbering an applied migration has no undo | Fixed |

## E. Stage 16 slice B — seeding framework

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-16-11 | Medium | composer.json / ext | Found by inventorying every package's `ext-*` block while closing F-16-03 — the same class of bug, one layer down: `packages/auth`, `packages/bus`, `packages/console` and `packages/laravel-flavor` all use `PDO` in `src/` but declare **no** `ext-pdo`, while `http` and `persistence` do. A consumer installing only `lombokclarion/auth` on a PDO-less build gets a runtime fatal where Composer should have refused to install | Add `ext-pdo` to those four `composer.json` requires. Not applied this session: F-16-03's scope was the mbstring/fileinfo pair, and widening a "targeted fix" into four more packages without the user's call is the thing the working rules forbid | Deferred (user decision, one-line change per package) |
| F-16-12 | Medium | persistence (seeding) | There was no seeding path at all: reference data (roles, statuses, lookup rows) had to be hand-inserted or smuggled into a migration's `up()`, which makes data indistinguishable from schema in the migrations table and un-rerunnable by design | New `Seeder` interface + `SeederRunner`, an explicit ordered manifest (`bootstrap/seeders.php`, hand-written like `bootstrap/migrations.php` — never a directory scan, §2.5), and a `seeders` tracking table mirroring `migrations` | Fixed |
| F-16-13 | High | persistence (seeding) | **The central design decision, recorded so it is not "simplified" later.** The mainstream framework behaviour — re-run every seeder on every `seed` — means a second run silently doubles a reference-data seeder's rows unless every author remembered a hand-written existence check. That is exactly the AUDIT-TRAIL #45 shape: a correctness property authored by convention and enforced by nothing | Seeders are **tracked**, so run-once is structural and `seed` is safe next to `migrate` in a deploy pipeline. Re-running is possible but must be asked for by name (`--only=` + `--force`) | Accepted (deliberate) |
| F-16-14 | Medium | persistence (seeding) | A seeder that failed halfway could leave rows behind while its tracking row said "applied" — the state in which nothing afterwards can tell what actually landed, and which (unlike a half-applied migration) leaves no trace in the schema | `SeederRunner` wraps **the seeder's DML and its `seeders` row in one transaction**, unconditionally. `Seeder` therefore has no `runsInTransaction()` hook, unlike `Migration`: migrations need one because DDL is non-transactional on MySQL; seeders issue only DML, which every supported driver rolls back, so there is no case in which opting out is correct. Pinned by a test that inserts then throws and asserts both the rows and the record are gone | Fixed |
| F-16-15 | Medium | persistence (seeding) | Filler data drawn from an unseeded global source cannot be reproduced: a fixture that breaks something breaks it once and is never seen again | `Factory` takes a seed in its **only** constructor and draws from `Random\Randomizer` over an explicitly named `Mt19937` engine — the engine is named rather than defaulted so a future PHP changing the default cannot silently change what an old seed produces. There is no unseeded construction path. `seed` echoes the seed on **every** run, passed or not, because the run that turns out to need reproducing is never the one you thought to record | Fixed |
| F-16-16 | Low | persistence (seeding) | Seeded rows that escape into a real environment can read as plausible people, and a later mail job would happily deliver to them | `Factory::email()` is fixed to the RFC 2606 reserved `.invalid` TLD, which can never resolve; word lists are obviously synthetic. Pinned by a test | Fixed |
| F-16-17 | Medium | console (seeding) | `--force` re-runs a seeder, and re-running duplicates rows unless the seeder happens to be idempotent — which nothing in the type system can promise | `seed --force` **requires** `--only=ClassName`. A bare `seed --force` re-running the whole manifest would make the destructive case the shortest thing to type; requiring the name makes the operator state which duplication they are choosing. Refusal is pinned by a test asserting nothing was seeded | Accepted (deliberate) |
| F-16-18 | Low | console (seeding) | **Found by smoke-testing, not by reading:** a forced re-run at the *same* seed regenerates the same values, so any unique column collides and the transaction rolls back — correct behaviour (determinism refusing to duplicate), but the operator saw only the driver's raw `UNIQUE constraint failed` and had to infer why | `SeedCommand` appends an explanation whenever `--force` was used: same seed → identical values → collision → nothing duplicated, with the two real options (different `--seed=N`, or delete the rows first). The collision-and-rollback behaviour itself is pinned by a test | Fixed |
| F-16-19 | Low | console (seeding) | No read-only way to ask whether a database's *data* was current, mirroring the gap F-16-05 closed for schema | New `seed:status`, deliberately the same shape as `migrate:status` down to `--strict` and the orphan rule — an operator should not have to learn two vocabularies for the same question about two halves of a database's state | Fixed |
| F-16-20 | Low | console (seeding) | An orphan (recorded seeder absent from the manifest) means something different here than for migrations, and the difference is worth stating rather than copying the message across | Still exit 1 with or without `--strict`, but for a different reason: a migration orphan blocks *rollback*; a seeder orphan blocks nothing, because data has no `down()`. It is an error because it is the only signal that rows exist which no manifest entry accounts for. `SeederRunner::forget()` clears the record **without** touching rows, and is named for what it does not do — there is no rollback for data to call it | Fixed |
| F-16-21 | Low | console (seeding) | Writing a seeder meant hand-copying one | New `make:seeder ClassName [--table=NAME]`, same guarantees as `make:migration`: class name `^[A-Z][A-Za-z0-9]*$` so namespace and file location cannot disagree and no path escape is expressible, `--table` a bare identifier, never overwrites, target directory and namespace are constructor config rather than discovery, and — per F-16-09's reasoning — it **does not edit `bootstrap/seeders.php`**. Generated output asserted to parse by its test | Fixed |

## F. Verification performed for slice B

Round-trip smoke run against the real app before any test was written:
fresh `seed:status` → 1 pending → `seed` → seeded + seed echoed → `seed` again
→ no-op → `seed --force` alone → refused → `--only --force` at the same seed →
collision, rollback, row count unchanged at 5 → `--only --force --seed=999` →
5 more rows → orphan row planted → exit 1 with and without `--strict` →
`make:seeder` → stub parses, manifest untouched → overwrite refused → four bad
class names and one injection-shaped `--table` rejected.

Determinism checked directly: two `Factory(4242)` instances produced identical
id/words/int/email; `Factory(4243)` differed.

## G. Artefak-contents audit (dilakukan setelah slice B dikemas)

Isi zip yang dikirim diperiksa dari ekstrak bersih, bukan dari direktori kerja:
19 package hadir dengan `composer.json` masing-masing, 263 file PHP semuanya
lolos `php -l`, seluruh kelas Stage 16 ter-autoload, dan suite 333 PASS dari
ekstrak murni. `packages/framework` yang tak punya file PHP bukan file hilang —
`"type": "metapackage"`.

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-16-22 | Low | composer.json | `lombokclarion/auth` dideklarasikan hanya di `require-dev`, padahal diimpor langsung oleh jalur boot produksi: `bootstrap/services.php`, `bootstrap/routes.php`, `bootstrap/console.php`, dan 5 file `app/`. **Koreksi terhadap perumusan pertama temuan ini:** saya awalnya menyatakan `composer install --no-dev` akan menghasilkan app yang fatal saat boot. Itu **salah**, dan dibuktikan salah oleh `composer install --no-dev --dry-run` pada artefak itu sendiri — auth tetap terpasang, karena `laravel-flavor` (ada di `require` produksi) me-require auth. Yang tersisa adalah kopling laten, bukan kerusakan hidup: auth sampai ke produksi hanya lewat jalur transitif menembus `laravel-flavor` — paket "magic" yang menurut filosofi framework justru harus bisa dilepas | `lombokclarion/auth` dipindah dari `require-dev` ke `require`. Aturan yang berlaku bukan "apakah saat ini terpasang" melainkan "dependensi langsung dideklarasikan langsung". `composer.lock` di-resync; `composer why --locked` kini menampilkannya sebagai require produksi root | Fixed |
| F-16-23 | Low | composer.json | Dari 19 package, hanya metapackage `lombokclarion/framework` yang tak punya entri path repository (18 entri untuk 19 package). Tak memblokir apa pun — root tak me-require framework — tapi berarti metapackage itu satu-satunya yang tak bisa diresolusi di monorepo-nya sendiri, sehingga perubahan padanya tak bisa diuji sebelum dipublikasikan | Entri path ditambahkan. `composer show lombokclarion/framework --available` kini meresolusinya | Fixed |
| F-16-24 | Info | composer.json | `lombokclarion/i18n` juga ada di `require-dev` sementara `packages/validation` (require produksi) me-requirenya — jadi ia selalu terpasang di produksi | Dibiarkan. Beda dengan F-16-22: tak ada kode `app/` atau `bootstrap/` yang mengimpor `LombokClarion\I18n` secara langsung, jadi ia memang bukan dependensi langsung aplikasi. Menaikkannya ke `require` berarti mendeklarasikan sesuatu yang tak dipakai aplikasi | Accepted |

**Catatan proses.** F-16-22 versi pertama adalah kegagalan yang persis dilarang
aturan kerja: mode kegagalannya saya *nyatakan* dari membaca `composer.json`,
bukan dari menjalankan apa pun. Perintah yang menyanggahnya butuh satu baris.
Temuannya tetap nyata setelah dikoreksi, tapi severity turun dari Medium ke Low
dan alasannya berubah total — dan itu hanya ketahuan karena diuji.

---

## H. Open / deferred (carried forward from STAGE-14/15)

| ID | Sev | Finding | Owner / next step |
|------|--------|---------|-------------------|
| F-14-15 | Medium | npm ↔ framework license mismatch | Re-publish npm `lombokclarion` as Apache-2.0, or annotate it as asset-tooling-only |
| F-14-16 | High | GitHub `main` far behind local | Session-N+1 Phase 1 (force-push + retag) |
| F-16-01 | High | `.github/` absent from the delivery artifact | Restore from canonical repo before claiming CI state |
| F-16-02 | High | Reconciliation decision 5 references a missing file | Re-frame the decision before answering it |
| F-16-04 | Info | PHPStan gate unverified in this environment | Run locally / in CI where Packagist is reachable |
| F-16-11 | Medium | `ext-pdo` undeclared in `auth`, `bus`, `console`, `laravel-flavor` | User decision: one line per `composer.json`, plus a `composer.lock` re-sync |

**Closed this session:** F-16-03 (group A), F-16-22 and F-16-23 (group G).

---

## Process notes

1. Findings A were produced by **running** the artifact, not by reading its
   handoff documents. The two High findings (F-16-01/02) are invisible to any
   review that trusts the docs, because the docs describe the files as present.
2. Every Deferred/Accepted item states a reason; every Fixed item is verified
   (suite + gates 1–3 green) before it is marked Fixed.
3. Carry unresolved items forward into Stage 17's table until closed.
