#!/usr/bin/env bash
#
# relicense.sh — flip the SPDX license identifier in every composer.json and in
# package.json from one identifier to another. The LICENSE / NOTICE text files
# are swapped by hand (they are not generated); this only touches the metadata
# so the 19 composer.json files and package.json can't drift from the LICENSE.
#
#   bin/relicense.sh MIT Apache-2.0        # what this repo did
#   bin/relicense.sh Apache-2.0 MIT        # to reverse it
#
# Targeted line replacement — preserves each file's existing formatting and key
# order (no JSON reformat). Idempotent. Vendored third-party assets under
# resources/ are never touched — they keep their own licenses.

set -euo pipefail
from="${1:?usage: relicense.sh <from-spdx> <to-spdx>}"
to="${2:?usage: relicense.sh <from-spdx> <to-spdx>}"

changed=0
for f in composer.json packages/*/composer.json package.json; do
    [ -f "$f" ] || continue
    if grep -q "\"license\": \"${from}\"" "$f"; then
        # match "license" with any surrounding whitespace, replace only the value
        sed -i "s/\(\"license\"[[:space:]]*:[[:space:]]*\)\"${from}\"/\1\"${to}\"/" "$f"
        echo "  relicensed: $f"
        changed=$((changed+1))
    fi
done

echo "relicense: ${changed} file(s) set to ${to}."
echo "Reminder: LICENSE and NOTICE are swapped by hand, and the README license"
echo "section is updated separately. Run 'composer validate' after."
