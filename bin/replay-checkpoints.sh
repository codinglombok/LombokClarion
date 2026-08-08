#!/usr/bin/env bash
#
# replay-checkpoints.sh — TEMPLATE for Release Playbook Strategy B.
#
# Rebuilds an authentic, staged git history by laying down each real checkpoint
# snapshot in order, one commit + one tag per checkpoint. Every state committed
# here actually existed and passed its suite, so the history is true, not staged
# theatre. See docs/RELEASE-PLAYBOOK.md §2.
#
# This is a TEMPLATE: edit the CHECKPOINTS list to the snapshots you actually
# kept (path → tag → message), extract each checkpoint zip to its own directory
# first, then run from a fresh clone of the monorepo. It force-builds history, so
# only run BEFORE the repo has external forks/contributors.
#
# Each entry: "<extracted-checkpoint-dir>|<tag>|<commit message>"
CHECKPOINTS=(
  "/path/to/checkpoint-base|v0.5.0|v0.5.0 — base snapshot (124 tests)"
  "/path/to/checkpoint-i18n|v0.6.0|v0.6.0 — i18n (131 tests)"
  "/path/to/checkpoint-auth|v0.7.0|v0.7.0 — auth + RBAC (186 tests)"
  "/path/to/checkpoint-multidb|v0.8.0|v0.8.0 — multi-database ConnectionManager (214 tests)"
  "/path/to/checkpoint-flavor|v0.9.0|v0.9.0 — laravel-flavor + compiled routes (235 tests)"
  "/path/to/checkpoint-validation|v0.10.0|v0.10.0 — validation (265 tests)"
  "/path/to/checkpoint-51|v1.0.0|v1.0.0 — storage/upload + PHPStan L5 + publishing (282 tests)"
)

set -euo pipefail
[ -d .git ] || { echo "run from the monorepo git root" >&2; exit 1; }

for entry in "${CHECKPOINTS[@]}"; do
    IFS='|' read -r dir tag msg <<< "$entry"
    [ -d "$dir" ] || { echo "missing checkpoint dir: $dir" >&2; exit 1; }

    echo ">> $tag  ($dir)"
    # Lay down exactly this snapshot (delete files not present in it), but never
    # touch .git itself. Generated artifacts are .gitignored so they won't commit.
    rsync -a --delete --exclude='.git' "${dir%/}/" .

    git add -A
    git commit -m "$msg" --allow-empty
    git tag -a "$tag" -m "$msg"
done

echo
echo "Replayed ${#CHECKPOINTS[@]} checkpoints. Review with 'git log --oneline --decorate',"
echo "then: git push --force origin main --tags   (splits + Packagist follow the tags)"
