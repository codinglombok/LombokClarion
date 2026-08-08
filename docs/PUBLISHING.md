# Publishing LombokClarion

This resolves the decision that had been parked since the monorepo was first
assembled: **how the 20 packages reach consumers.**

## Decision: monorepo is canonical, read-only subtree splits feed Packagist

- **`github.com/codinglombok/LombokClarion` is the one canonical repository.**
  All development, issues, PRs, CI, and tags happen here. It is a Composer
  monorepo: the 18 framework packages live under `packages/*`, wired to each
  other as `path` repositories, plus the example/harness app at the root.
- **Each package is mirrored, read-only, to its own repository** by a
  `git subtree split`. Those mirrors are what Packagist watches. A consumer
  runs `composer require lombokclarion/http` and Packagist resolves it from the
  split repo — never from this monorepo.

Nobody develops in the split repos; they carry no issues and accept no PRs (a
short notice in each mirror's README points contributors back here). This is the
same model Laravel (`laravel/framework` → `illuminate/*`) and Symfony use, for
the same reason: one place to change code and run the whole suite, many
independently-installable packages.

### Why not the alternatives

- **Monorepo, `composer require` the whole thing.** Packagist reads exactly one
  `composer.json` per repository — the root. It cannot publish 18 sub-packages
  from one repo. A consumer could add this repo as a VCS repository and pull
  packages by path, but that forces the entire framework + example app into
  every project and bypasses Packagist versioning. Rejected.
- **18 hand-maintained package repos, no monorepo.** Every cross-package change
  (and they are common here — see the dependency graph below) becomes a
  multi-repo, multi-PR dance with no single place to run the 282-test suite or
  the four gates. Rejected.

## The vendor / org split, stated explicitly

The Packagist vendor and the GitHub org are deliberately **different names**, and
this is allowed — Packagist does not require the vendor to match the GitHub owner:

| Thing                    | Value                                   |
|--------------------------|-----------------------------------------|
| Packagist vendor         | `lombokclarion` (e.g. `lombokclarion/http`) |
| GitHub org               | `codinglombok`                          |
| Monorepo                 | `github.com/codinglombok/LombokClarion` |
| Split mirror for `http`  | `github.com/codinglombok/http`          |
| Installed as             | `composer require lombokclarion/http`   |

Each package's `composer.json` already declares `"name": "lombokclarion/<pkg>"`,
so the mirror publishes under the right vendor no matter what the GitHub repo is
called. When registering a mirror on Packagist, submit the GitHub URL
(`codinglombok/<pkg>`); Packagist reads the name from `composer.json`.

## Inter-package dependencies resolve unchanged after the split

Every cross-package constraint in the monorepo is `*` (loose on purpose for a
pre-1.0 line). That is exactly what makes the split mechanical: after `http` is
mirrored, its `composer.json` still says `"lombokclarion/persistence": "*"`, and
Packagist resolves that against the *other* published mirror. No constraint
rewrite is needed to go from path-repos-in-monorepo to Packagist-published.

The dependency graph (who requires whom) — split order does not matter because
`*` tolerates any publish order, but this is the shape:

```
container   ← bus, console, facades, routing, testing, laravel-flavor
http        ← auth, i18n, persistence, routing, security, storage, testing, validation
persistence ← active-record, auth, console, http
security    ← auth
routing     ← console, testing
i18n        ← validation
config,view ← console
active-record, auth, facades, security ← laravel-flavor
```

`phpstan-rules` depends on nothing in the set and can split first or last.

## Release / tag flow

1. Land changes on `main` in the monorepo; the full suite + four gates run in CI.
2. Tag the monorepo: `git tag vX.Y.Z && git push --tags`.
3. The split workflow (`.github/workflows/split.yml`) mirrors `main` and the tag
   into each `codinglombok/<pkg>` mirror. Every package shares the monorepo's
   version — they are released in lockstep (again, the Laravel/Symfony model),
   so `lombokclarion/http vX.Y.Z` and `lombokclarion/auth vX.Y.Z` always agree.
4. Packagist, subscribed to each mirror, picks up the new tag automatically.

Versions stay `0.x` until the API is declared stable; while on `0.x`, a consumer
pins with `^0.y` and SemVer treats the minor as the breaking axis.

## Doing a split

`bin/split-package.sh` is the inspectable, no-CI way to mirror one package. It is
pure `git subtree` — no third-party tooling, consistent with how this repo keeps
its moving parts local and auditable:

```bash
# one package, to its mirror (branch-level; run from a clean monorepo checkout)
bin/split-package.sh http git@github.com:codinglombok/http.git main
```

`.github/workflows/split.yml` runs the same split for all 20 packages on every
push to `main` and on tags. It needs a secret, `SPLIT_TOKEN` — a machine-user PAT
(or per-repo deploy keys) with push access to the `codinglombok/*` mirrors. The
workflow is force-push (`--force`) into the mirrors by design: the mirrors are
derived artifacts, never a source of truth, so their history is always the
subtree projection of the monorepo's.

## First-time setup checklist

- [ ] Create empty `codinglombok/<pkg>` repos for all 20 packages.
- [ ] Add `SPLIT_TOKEN` to the monorepo's Actions secrets.
- [ ] Push the monorepo to `codinglombok/LombokClarion`; let CI go green.
- [ ] Run the split workflow once (or `bin/split-package.sh` per package) to seed
      the mirrors.
- [ ] Submit each mirror URL to Packagist and enable its auto-update hook.
- [ ] Tag `v0.1.0` on the monorepo; confirm the tag reaches every mirror and
      Packagist.
