#!/usr/bin/env bash
# Build the installable Joomla package for the Spamtroll system plugin.
#
# Output: dist/plg_system_spamtroll-<version>.zip with the manifest at the
# top level, ready to be uploaded via Extensions → Install.
#
# The archive has to carry the Spamtroll PHP SDK in vendor/. Joomla's
# autoloader only knows the plugin's own namespace, so without it
# services/provider.php cannot class-load JoomlaHttpClient (it implements
# Spamtroll\Sdk\Http\HttpClientInterface) and, because system plugins are
# imported during application bootstrap, the failure takes down every request
# to the site. The build aborts rather than emitting a package like that.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="${ROOT}/plg_system_spamtroll"
DIST="${ROOT}/dist"
MANIFEST="${SRC}/spamtroll.xml"
ROOT_COMPOSER="${ROOT}/composer.json"

if [[ ! -f "${MANIFEST}" ]]; then
    echo "error: manifest not found at ${MANIFEST}" >&2
    exit 1
fi

for tool in composer php zip; do
    if ! command -v "${tool}" >/dev/null 2>&1; then
        echo "error: ${tool} is required to build the package" >&2
        exit 1
    fi
done

# Extract <version>X.Y.Z</version> from the manifest. Tolerates extra
# whitespace but expects the tag on a single line, which the bundled manifest
# always honours.
VERSION="$(grep -oE '<version>[^<]+</version>' "${MANIFEST}" | head -n1 | sed -E 's|</?version>||g' | tr -d '[:space:]')"

if [[ -z "${VERSION}" ]]; then
    echo "error: could not extract <version> from ${MANIFEST}" >&2
    exit 1
fi

# The SDK constraint is single-sourced from the root composer.json so the
# packaged copy can never drift from what the test suite runs against.
SDK_CONSTRAINT="$(php -r '
    $manifest = json_decode(file_get_contents($argv[1]), true);
    echo $manifest["require"]["spamtroll/php-sdk"] ?? "";
' "${ROOT_COMPOSER}")"

if [[ -z "${SDK_CONSTRAINT}" ]]; then
    echo "error: spamtroll/php-sdk is not required by ${ROOT_COMPOSER}" >&2
    exit 1
fi

ZIP_NAME="plg_system_spamtroll-${VERSION}.zip"
ZIP_PATH="${DIST}/${ZIP_NAME}"

mkdir -p "${DIST}"
rm -f "${ZIP_PATH}"

# Stage the plugin contents in a temp dir so the manifest ends up at the top
# level of the archive (Joomla's installer requires that).
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

cp -R "${SRC}/." "${TMP}/"
cp "${ROOT}/LICENSE" "${TMP}/LICENSE"

# Install the SDK against a throwaway composer.json rooted in the staging
# directory. Reusing the repository's own composer.json would bake the
# repository layout into vendor/composer/autoload_psr4.php and map the
# plugin's namespace to a path that does not exist on the target site.
cat > "${TMP}/composer.json" <<COMPOSER_JSON
{
    "name": "spamtroll/joomla-package",
    "description": "Runtime dependencies bundled into the installable Joomla package.",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "require": {
        "spamtroll/php-sdk": "${SDK_CONSTRAINT}"
    },
    "config": {
        "optimize-autoloader": true,
        "platform": {
            "php": "8.2"
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
COMPOSER_JSON

(
    cd "${TMP}"
    composer update \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --classmap-authoritative \
        --quiet
)

rm -f "${TMP}/composer.json" "${TMP}/composer.lock"

# Guard rails: a package without these is worse than no package at all.
for required in \
    "${TMP}/vendor/autoload.php" \
    "${TMP}/vendor/spamtroll/php-sdk/src/Client.php" \
    "${TMP}/vendor/spamtroll/php-sdk/src/Http/HttpClientInterface.php"
do
    if [[ ! -f "${required}" ]]; then
        echo "error: expected ${required#"${TMP}/"} in the staged package" >&2
        exit 1
    fi
done

# Strip development-only artefacts that may have leaked into the source tree.
find "${TMP}" -name '.DS_Store' -delete
find "${TMP}" -name 'Thumbs.db' -delete

(
    cd "${TMP}"
    zip -rq "${ZIP_PATH}" .
)

# Verify against the archive itself, not the staging directory — a mistake in
# the zip invocation would otherwise go unnoticed.
for entry in \
    'spamtroll.xml' \
    'LICENSE' \
    'services/provider.php' \
    'vendor/autoload.php' \
    'vendor/spamtroll/php-sdk/src/Client.php'
do
    if ! unzip -l "${ZIP_PATH}" "${entry}" >/dev/null 2>&1; then
        echo "error: ${entry} missing from ${ZIP_PATH}" >&2
        exit 1
    fi
done

php "${ROOT}/build/build-update.php" "${ZIP_PATH}" "${MANIFEST}" "${DIST}"

echo "built ${ZIP_PATH}"
echo "  SDK: $(unzip -p "${ZIP_PATH}" vendor/composer/installed.json | php -r '
    $installed = json_decode(stream_get_contents(STDIN), true);
    foreach ($installed["packages"] ?? [] as $package) {
        if ($package["name"] === "spamtroll/php-sdk") {
            echo $package["version"];
        }
    }
')"
