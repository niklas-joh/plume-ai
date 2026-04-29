#!/usr/bin/env bash
# bin/build-wporg.sh — produce a WP.org-ready zip of the plugin.
# Usage: ./bin/build-wporg.sh [--version 1.2.3] [--skip-build]
#   --skip-build  Skip npm and composer steps (use when CI has already run them).
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="wp-ai-mind"
VERSION=""
SKIP_BUILD=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --version) VERSION="$2"; shift 2 ;;
        --skip-build) SKIP_BUILD=true; shift ;;
        *) shift ;;
    esac
done

# Fall back to version from main plugin file
if [[ -z "$VERSION" ]]; then
    VERSION=$(grep "Version:" "${PLUGIN_DIR}/wp-ai-mind.php" | head -1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
fi

DIST_DIR="${PLUGIN_DIR}/dist"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
BUILD_DIR="${DIST_DIR}/${PLUGIN_SLUG}"

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean previous build
rm -rf "${DIST_DIR}"
mkdir -p "${BUILD_DIR}"

if [[ "$SKIP_BUILD" == "false" ]]; then
    echo "Running npm build..."
    cd "${PLUGIN_DIR}" && npm run build

    echo "Installing PHP dependencies..."
    cd "${PLUGIN_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction
fi

# Copy only production files (allowlist — safe by default, new dev tooling never leaks in)
rsync -a \
    --include='includes/***' \
    --include='languages/***' \
    --include='vendor/***' \
    --include='assets/***' \
    --include='wp-ai-mind.php' \
    --include='readme.txt' \
    --include='uninstall.php' \
    --include='CHANGELOG.md' \
    --exclude='*' \
    "${PLUGIN_DIR}/" "${BUILD_DIR}/"

# Create zip
cd "${DIST_DIR}"
zip -r "${ZIP_NAME}" "${PLUGIN_SLUG}/"
echo "Created: ${DIST_DIR}/${ZIP_NAME}"

# Show contents summary
echo ""
echo "Contents:"
unzip -l "${ZIP_NAME}" | tail -5
