#!/usr/bin/env bash
# Replaces @since NEXT_VERSION with the actual release version in all PHP files.
# Called by semantic-release prepareCmd. Safe when no placeholders exist.
set -euo pipefail

VERSION="${1:?Usage: stamp-since-tags.sh <version>}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# STAMP_REPO_ROOT can be overridden in tests to point at a temp directory.
REPO_ROOT="${STAMP_REPO_ROOT:-$(cd "${SCRIPT_DIR}/.." && pwd)}"

# Distinguish no-match (exit 1, expected) from a real grep error (exit 2, abort).
set +e
MATCHED_FILES=$(
  grep -rl --include="*.php" \
    --exclude-dir=vendor \
    --exclude-dir=assets \
    --exclude-dir=dist \
    "@since NEXT_VERSION" \
    "${REPO_ROOT}"
)
GREP_EXIT=$?
set -e

if [[ $GREP_EXIT -eq 2 ]]; then
  echo "stamp-since-tags: grep error — aborting." >&2
  exit 1
fi

if [[ -z "$MATCHED_FILES" ]]; then
  echo "stamp-since-tags: no @since NEXT_VERSION placeholders found — nothing to stamp."
  exit 0
fi

mapfile -t _since_files <<< "$MATCHED_FILES"
perl -pi -e "s|\@since NEXT_VERSION|\@since ${VERSION}|g" "${_since_files[@]}"  # \@ prevents Perl array interpolation

FILE_COUNT=$(echo "$MATCHED_FILES" | wc -l | tr -d ' ')
echo "stamp-since-tags: updated files:"
echo "$MATCHED_FILES" | sed "s|${REPO_ROOT}/||"
echo "stamp-since-tags: stamped @since NEXT_VERSION → @since ${VERSION} in the ${FILE_COUNT} file(s) above."

# Stamp the POT file header separately — it uses bare NEXT_VERSION (no @since prefix)
# and is not a PHP file, so it is excluded from the grep above.
POT_FILE="${REPO_ROOT}/languages/plume.pot"
if [[ -f "$POT_FILE" ]]; then
  perl -pi -e "s|NEXT_VERSION|${VERSION}|g" "$POT_FILE"
  echo "stamp-since-tags: stamped NEXT_VERSION → ${VERSION} in languages/plume.pot"
fi
