#!/usr/bin/env bash
# Build the installable Joomla package for the Spamtroll system plugin.
#
# Output: dist/plg_system_spamtroll-<version>.zip with the manifest at the
# top level, ready to be uploaded via Extensions → Install.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="${ROOT}/plg_system_spamtroll"
DIST="${ROOT}/dist"
MANIFEST="${SRC}/spamtroll.xml"

if [[ ! -f "${MANIFEST}" ]]; then
    echo "error: manifest not found at ${MANIFEST}" >&2
    exit 1
fi

# Extract <version>X.Y.Z</version> from the manifest. Tolerates extra
# whitespace but expects the tag on a single line, which the bundled manifest
# always honours.
VERSION="$(grep -oE '<version>[^<]+</version>' "${MANIFEST}" | head -n1 | sed -E 's|</?version>||g' | tr -d '[:space:]')"

if [[ -z "${VERSION}" ]]; then
    echo "error: could not extract <version> from ${MANIFEST}" >&2
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

# Strip development-only artefacts that may have leaked into the source tree.
find "${TMP}" -name '.DS_Store' -delete
find "${TMP}" -name 'Thumbs.db' -delete

(
    cd "${TMP}"
    zip -rq "${ZIP_PATH}" .
)

echo "built ${ZIP_PATH}"
