#!/usr/bin/env bash
# Replaces @since NEXT_VERSION with the actual release version in all PHP files.
# Called by semantic-release prepareCmd. Safe when no placeholders exist.
set -euo pipefail

VERSION="${1:?Usage: stamp-since-tags.sh <version>}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# grep exits 1 on no matches — || true prevents set -e from killing the script.
MATCHED_FILES=$(
  grep -rl --include="*.php" \
    --exclude-dir=vendor \
    --exclude-dir=assets \
    --exclude-dir=dist \
    "@since NEXT_VERSION" \
    "${REPO_ROOT}" || true
)

if [[ -z "$MATCHED_FILES" ]]; then
  echo "stamp-since-tags: no @since NEXT_VERSION placeholders found — nothing to stamp."
  exit 0
fi

FILE_COUNT=$(echo "$MATCHED_FILES" | wc -l | tr -d ' ')
echo "$MATCHED_FILES" | xargs sed -i "s/@since NEXT_VERSION/@since ${VERSION}/g"

echo "stamp-since-tags: stamped @since NEXT_VERSION → @since ${VERSION} in ${FILE_COUNT} file(s):"
echo "$MATCHED_FILES" | sed "s|${REPO_ROOT}/||"
