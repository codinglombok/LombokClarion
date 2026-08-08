#!/usr/bin/env bash
#
# split-package.sh — mirror one monorepo package to its own read-only repository.
#
# Pure `git subtree`: no third-party tooling, so the split mechanism stays as
# inspectable as the rest of this repo. See docs/PUBLISHING.md for the decision
# this implements (monorepo canonical, read-only splits feed Packagist).
#
#   bin/split-package.sh <package-dir> <target-git-url> [ref]
#
# e.g.
#   bin/split-package.sh http git@github.com:codinglombok/http.git main
#
# The push is a force-push by design: the mirror is a derived artifact whose
# history is always the subtree projection of the monorepo — never edited there.

set -euo pipefail

pkg="${1:?usage: split-package.sh <package-dir> <target-git-url> [ref]}"
target="${2:?target git url, e.g. git@github.com:codinglombok/http.git}"
ref="${3:-main}"

prefix="packages/${pkg}"
[ -d "$prefix" ] || { echo "split-package: no such package: $prefix" >&2; exit 1; }
[ -f "${prefix}/composer.json" ] || {
    echo "split-package: ${prefix} has no composer.json — is it a package?" >&2
    exit 1
}

branch="split/${pkg}"
git rev-parse --verify --quiet "refs/heads/${branch}" >/dev/null && git branch -D "$branch"

echo "split-package: splitting ${prefix} → ${branch}"
git subtree split --prefix="$prefix" -b "$branch" >/dev/null

echo "split-package: pushing ${branch} → ${target} (${ref})"
git push --force "$target" "${branch}:${ref}"

git branch -D "$branch" >/dev/null
echo "split-package: ${pkg} mirrored."
