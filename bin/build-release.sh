#!/usr/bin/env bash
# Build a distribution ZIP for slashbooking.
# Usage: bin/build-release.sh [version]
#        version defaults to the value read from src/Plugin.php

set -euo pipefail

PLUGIN_SLUG="slashbooking"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build"
STAGING_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
SCOPED_DIR="${BUILD_DIR}/scoped"

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    VERSION=$(grep -E "^[[:space:]]*public const VERSION" "${ROOT_DIR}/src/Plugin.php" \
        | sed -E "s/.*'([^']+)'.*/\1/")
fi
ZIP_PATH="${BUILD_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

echo "→ Building ${PLUGIN_SLUG} v${VERSION}"

# 1. Clean previous build
rm -rf "${BUILD_DIR}"
mkdir -p "${STAGING_DIR}" "${SCOPED_DIR}"

# 2. Install Composer prod dependencies (without dev) into vendor/
echo "→ composer install --no-dev (production deps)"
(cd "${ROOT_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)

# 3. Build npm assets (production webpack)
echo "→ npm run build (SPA assets)"
(cd "${ROOT_DIR}" && npm ci --silent && npm run build --silent)

# 4. Run PHP-Scoper to produce scoped src/ + vendor/
echo "→ php-scoper (prefix Slash\\Booking\\Vendor)"
# Re-install dev to get php-scoper binary
(cd "${ROOT_DIR}" && composer install --quiet)
# Memory limit 1G is required: scoper processes ~34k files
(cd "${ROOT_DIR}" && php -d memory_limit=1G vendor/bin/php-scoper add-prefix \
    --config=scoper.inc.php \
    --output-dir="${SCOPED_DIR}" \
    --force \
    --no-interaction \
    --quiet)

# 5. Regenerate Composer autoload classmap inside the scoped tree
echo "→ composer dump-autoload (scoped, classmap-authoritative)"
# Copy a minimal composer.json into scoped dir for dump-autoload to work
cp "${ROOT_DIR}/composer.json" "${SCOPED_DIR}/composer.json"
# Strip require-dev (we don't ship test deps) AND inject a classmap entry that
# scans vendor/ directly. Without this, composer would need vendor/composer/installed.json
# to enumerate prefixed packages — but scoper doesn't produce one, so vendor classes
# would not be autoloadable (Class Slash\Booking\Vendor\Google\Client not found).
SCOPED_COMPOSER="${SCOPED_DIR}/composer.json" php -r '
$path = getenv("SCOPED_COMPOSER");
$j = json_decode(file_get_contents($path), true);
unset($j["require-dev"], $j["autoload-dev"], $j["scripts"]);
$j["autoload"]["classmap"] = ["vendor/"];
file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
'
(cd "${SCOPED_DIR}" && composer dump-autoload --classmap-authoritative --no-interaction --quiet)

# 6. Stage final tree
echo "→ staging files into ${STAGING_DIR}"
cp -R "${SCOPED_DIR}/src" "${STAGING_DIR}/src"
cp -R "${SCOPED_DIR}/vendor" "${STAGING_DIR}/vendor"
cp "${ROOT_DIR}/slashbooking.php" "${STAGING_DIR}/slashbooking.php"
cp "${ROOT_DIR}/uninstall.php" "${STAGING_DIR}/uninstall.php"
cp "${ROOT_DIR}/README.md" "${STAGING_DIR}/README.md"
cp "${ROOT_DIR}/CHANGELOG.md" "${STAGING_DIR}/CHANGELOG.md" 2>/dev/null || true
cp -R "${ROOT_DIR}/assets" "${STAGING_DIR}/assets"
cp -R "${ROOT_DIR}/languages" "${STAGING_DIR}/languages"
# Strip JSX sources from staged copy (we shipped only assets/dist)
rm -rf "${STAGING_DIR}/src/Admin/react-app"
# Copy non-PHP public assets that scoper skipped (it only globs *.php).
# These are vanilla JS/CSS that don't need namespace prefixing.
mkdir -p "${STAGING_DIR}/src/PublicFront/assets"
cp -R "${ROOT_DIR}/src/PublicFront/assets/." "${STAGING_DIR}/src/PublicFront/assets/"

# 7. ZIP
echo "→ packaging ZIP ${ZIP_PATH}"
(cd "${BUILD_DIR}" && zip -r -q "${ZIP_PATH}" "${PLUGIN_SLUG}")

# 8. Checksum
CHECKSUM=$(shasum -a 256 "${ZIP_PATH}" | awk '{print $1}')
echo "${CHECKSUM}  $(basename "${ZIP_PATH}")" > "${ZIP_PATH}.sha256"

# 9. Done
SIZE=$(du -h "${ZIP_PATH}" | awk '{print $1}')
echo ""
echo "✓ Release built:"
echo "  File:     ${ZIP_PATH}"
echo "  Size:     ${SIZE}"
echo "  SHA-256:  ${CHECKSUM}"
