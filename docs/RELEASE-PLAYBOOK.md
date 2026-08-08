# Release Playbook

How LombokClarion goes from this monorepo to installable packages, and how to
give the public history a staged, versioned trace. Read `docs/PUBLISHING.md`
first for the monorepo-vs-split decision this builds on.

---

## 0. First, clear up three different "packages"

These are constantly confused. They are three separate systems:

| System              | What it is                                   | Used by LombokClarion?                          |
|---------------------|----------------------------------------------|-------------------------------------------------|
| **Packagist**       | The registry Composer/PHP installs from      | **Yes** — this is where `lombokclarion/*` lives |
| **GitHub Packages** | GitHub's own registry (npm, Docker, NuGet…)  | **No** — the empty "Packages" box on the repo sidebar is this, and it is fine to leave empty |
| **npm**             | The registry for JavaScript                  | **Only** for the vendored CSS/JS assets, if at all — never for the PHP framework |

So the repo's **"No packages published"** notice is about *GitHub Packages*, and
it is expected — a PHP framework is not distributed there. Publishing means
**registering the split mirrors on Packagist**, covered in §3.

---

## 1. Reconcile the published repo with the current code (do this first)

The repository currently on GitHub is an **early snapshot** — its README says
"124 tests, 12 packages" and it has no `auth`, `multi-db`, `laravel-flavor`,
`validation`, or `storage`. The current code (this checkpoint) is **282 tests,
20 packages**, at Apache-2.0, with the publishing tooling in place. `main` has to
be brought up to the current state before any split, or the split's 18-package
matrix will reference packages that aren't there yet.

Also: the release tag is **`v.1.0.0`** — that extra dot is not valid SemVer, and
Packagist derives versions from tags. It should be **`v1.0.0`**.

You have two honest ways to reconcile, depending on whether you care about the
public history *showing* the staged build. Pick one — §2 details each.

---

## 2. Two release-history strategies

### Strategy A — Forward-only (simplest, recommended unless you specifically want the staged trace)

Treat the current 282-test state as the real 1.0.0 and release forward from here.

1. Bring `main` up to this checkpoint in a small number of honest commits
   (e.g. one "Catch up to Stage 13, PHPStan level 5, Apache-2.0" commit, or a few
   grouped by theme — not one per historical stage you didn't commit at the time).
2. Delete the malformed tag and create the correct one:
   ```bash
   git push origin :refs/tags/v.1.0.0      # delete remote bad tag
   git tag -d v.1.0.0 2>/dev/null || true
   git tag -a v1.0.0 -m "LombokClarion 1.0.0"
   git push origin main --tags
   ```
3. From here, every real change is a new SemVer tag (`v1.1.0`, `v1.1.1`, …). The
   *staged* story of how 1.0 was built already lives in `CHANGELOG.md` and
   the design spec (internal doc) — that is the honest place for it.

Trade-off: the git graph won't re-enact stages 6→13. But nothing is faked, and
Packagist versioning is clean.

### Strategy B — Replay the checkpoints as a staged history (authentic, more work)

You want the public history and Packagist versions to *show* the progression
(stage 8 → 9 → 10 → 11 → 12 → 13). The authentic way to get that — without
inventing commits — is to replay the **checkpoint snapshots that actually
existed**, in order, each as one commit + one tag. Each checkpoint was a real,
tested state, so this history is true, not staged theatre.

Rough version map (adjust to the checkpoints you actually kept):

| Tag       | State                                 | Tests |
|-----------|---------------------------------------|-------|
| `v0.5.0`  | current GitHub snapshot (base)        | 124   |
| `v0.6.0`  | i18n                                  | 131   |
| `v0.7.0`  | auth + RBAC                           | 186   |
| `v0.8.0`  | multi-database ConnectionManager      | 214   |
| `v0.9.0`  | laravel-flavor + compiled routes      | 235   |
| `v0.10.0` | validation                            | 265   |
| `v1.0.0`  | storage/upload + PHPStan L5 + publish | 282   |

Procedure per checkpoint (scripted in `bin/replay-checkpoints.sh` — a template):

```bash
# on a clean working copy, oldest checkpoint first:
rsync -a --delete --exclude .git CHECKPOINT_N/ .   # lay down that state
git add -A
git commit -m "vX.Y.Z — <stage name>"
git tag -a vX.Y.Z -m "<stage name>"
```

Then `git push origin main --tags` once, and the split workflow (tag-aware)
mirrors every tag into every package repo → Packagist shows the whole sequence.

Trade-offs: you must have (or rebuild) each intermediate checkpoint; pre-1.0
consumers see many `0.x` releases; the final push is a history replacement, which
is safe here only because there are no external forks/contributors yet. Do it
**before** anyone forks.

> Recommendation: unless the staged trace is a real goal (portfolio, teaching),
> use **Strategy A**. It is less work and just as honest — the stages are already
> narrated in the CHANGELOG.

---

## 3. Publish to Packagist (the actual "publish your packages" step)

Packagist watches the **split mirrors**, not this monorepo. So the order is:

1. **Split the packages** (needs `main` current — §1). Either run the
   `Split packages` workflow (after adding the `SPLIT_TOKEN` secret and creating
   the 18 empty `codinglombok/<pkg>` repos), or locally for a first seed:
   ```bash
   for p in container http routing bus config persistence view console \
            security testing active-record facades i18n phpstan-rules \
            auth laravel-flavor validation storage; do
     bin/split-package.sh "$p" "git@github.com:codinglombok/$p.git" main
   done
   ```
   (On each freshly-created mirror, set its default branch to `main` once.)

2. **Register each mirror on Packagist.** At https://packagist.org/packages/submit
   paste the mirror URL (`https://github.com/codinglombok/http`, …). Packagist
   reads `composer.json`, so it publishes under the **`lombokclarion/`** vendor
   even though the GitHub owner is `codinglombok`. Submit all 18.

3. **Enable auto-updates.** Add the Packagist GitHub webhook/integration (or the
   Packagist GitHub App) to the `codinglombok` org so a push/tag on any mirror
   refreshes Packagist automatically. Without it, Packagist only updates when you
   click "Update" or on the daily crawl.

4. **Tag to release a version.** A tag on the monorepo → split workflow mirrors it
   → Packagist picks up `lombokclarion/http vX.Y.Z`. All 18 share the version
   (lockstep), so a consumer's `composer require lombokclarion/http:^1.0` resolves.

Verify:
```bash
composer require lombokclarion/routing lombokclarion/http   # in a scratch project
```

---

## 4. npm — only the frontend assets, and only if you want to

The PHP framework does **not** belong on npm. The repo's `package.json` is a
*dev-tooling* manifest: its scripts refresh the vendored CSS/JS from LombokCSS and
LombokCharts (`npm run assets:update`), and its only dependency is `lombok-charts`.
That is its correct role — leave it as dev tooling.

State of the npm registry (verified 2026-07-19): `lombokclarion` **1.0.0**,
`lombokcss` **0.1.0**, and `lombok-charts` **0.1.1** are all published, so the npm
badges resolve — they do **not** 404. Two things to note:

- The published `lombokclarion` npm package is the *asset-tooling* manifest
  (license MIT there), not a usable JS build of the framework. If you keep it,
  make its license agree with the framework decision — see `docs/BADGES.md`'s
  accuracy caveat (npm says MIT, framework is now Apache-2.0).
- If you'd rather not present an npm package for the PHP framework at all, drop
  the `lombokclarion` npm entry and its two README badges, and keep only
  `lombokcss` / `lombok-charts` (which are genuinely JS/CSS and belong on npm).

To (re)publish the asset bundle, narrow `package.json` `files` to `resources/` +
`LICENSE` + `README.md`, then `npm publish --access public` (after `npm login`);
`.github/workflows/npm-publish.yml` can automate it on a tag.

Badge markdown for all three projects — accurate, grouped, copy-paste — lives in
`docs/BADGES.md`.

---

## 5. License change (done in this checkpoint)

The project moved **MIT → Apache-2.0**. This is allowed: `codinglombok` is the
sole copyright holder (no external contributors yet), so relicensing needs no
CLA or contributor sign-off. One caveat, handled: the **vendored assets under
`resources/` stay MIT** — you can't relicense someone else's work — recorded in
`NOTICE` and their `LICENSE-*` files.

What changed: `LICENSE` (now Apache-2.0 full text), new `NOTICE`, `"license":
"Apache-2.0"` in all 19 `composer.json` + `package.json` (via
`bin/relicense.sh MIT Apache-2.0`), and the README license section. Reverse with
`bin/relicense.sh Apache-2.0 MIT` + swapping the text files back.

Why Apache-2.0 over MIT: adds an explicit patent grant and a trademark clause —
useful for a framework meant to be adopted. If you'd rather keep MIT's brevity,
the change is fully reversible as above; decide before the first tagged release,
because the license of a published version is fixed for that version.

---

## 6. One-page checklist

- [ ] Bring `main` up to the current 282-test state (§1), Strategy A or B (§2).
- [ ] Replace tag `v.1.0.0` → `v1.0.0` (§2).
- [ ] Decide license: keep Apache-2.0 or revert (§5) — before first release tag.
- [ ] Create 18 empty `codinglombok/<pkg>` repos; add `SPLIT_TOKEN` secret.
- [ ] Seed the splits (workflow or `bin/split-package.sh`); set each mirror's
      default branch to `main`.
- [ ] Submit all 18 mirror URLs to Packagist; enable the auto-update webhook.
- [ ] Decide npm: remove the two badges, or narrow `package.json` and publish the
      asset bundle (§4).
- [ ] Tag `v1.0.0` on the monorepo; confirm it reaches every mirror + Packagist.
- [ ] `composer require lombokclarion/http` in a scratch project to prove it.
