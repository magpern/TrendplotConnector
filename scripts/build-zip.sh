#!/usr/bin/env bash
#
# Build a production release ZIP for trendplot-connector.
# Excludes all dev-only paths; produces builds/trendplot-connector-X.Y.Z.zip
#
set -euo pipefail

readonly PLUGIN_SLUG="trendplot-connector"
readonly MAIN_FILE_NAME="${PLUGIN_SLUG}.php"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

readonly SOURCE="${REPO_ROOT}"
readonly BUILD_DIR="${SOURCE}/builds"
readonly MAIN_FILE="${SOURCE}/${MAIN_FILE_NAME}"

echo "==> Trendplot Connector: build production release ZIP"
echo "    Source : ${SOURCE}"

[[ -f "${MAIN_FILE}" ]] || {
    echo "ERROR: main plugin file not found: ${MAIN_FILE}" >&2
    exit 1
}

HEADER_VERSION="$(
    grep -E '^\s*\*\s*Version:' "${MAIN_FILE}" \
        | head -n 1 \
        | sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//'
)"

CONST_VERSION="$(
    grep -E "define\s*\(\s*'TRENDPLOT_CONNECTOR_VERSION'" "${MAIN_FILE}" \
        | head -n 1 \
        | sed -E "s/.*'([0-9][^']+)'.*/\1/"
)"

if [[ -z "${HEADER_VERSION}" || -z "${CONST_VERSION}" ]]; then
    echo "ERROR: could not read Version / TRENDPLOT_CONNECTOR_VERSION from ${MAIN_FILE}" >&2
    exit 1
fi

if [[ "${HEADER_VERSION}" != "${CONST_VERSION}" ]]; then
    echo "ERROR: plugin header Version (${HEADER_VERSION}) does not match TRENDPLOT_CONNECTOR_VERSION (${CONST_VERSION})" >&2
    exit 1
fi

readonly VERSION="${CONST_VERSION}"
readonly ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
readonly ZIP_PATH="${BUILD_DIR}/${ZIP_NAME}"
readonly STAGING_DIR="${BUILD_DIR}/.package-${PLUGIN_SLUG}"
readonly PACKAGE_DIR="${STAGING_DIR}/${PLUGIN_SLUG}"

echo "    Version: ${VERSION}"
echo "    Output : ${ZIP_PATH}"

rm -rf "${STAGING_DIR}"
mkdir -p "${PACKAGE_DIR}" "${BUILD_DIR}"

echo "==> Copying production files (excluding dev-only paths)"
tar -C "${SOURCE}" \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='scripts' \
    --exclude='tests' \
    --exclude='docs' \
    --exclude='build' \
    --exclude='builds' \
    --exclude='.phpcs-cache' \
    --exclude='.phpunit.result.cache' \
    --exclude='README.md' \
    --exclude='CHANGELOG.md' \
    --exclude='.gitignore' \
    --exclude='.distignore' \
    --exclude='.editorconfig' \
    --exclude='.env' \
    --exclude='.env.*' \
    --exclude='*.log' \
    --exclude='.DS_Store' \
    --exclude='Thumbs.db' \
    -cf - . \
    | tar -C "${PACKAGE_DIR}" -xf -

echo "==> Creating ZIP archive"
rm -f "${ZIP_PATH}"
if command -v zip >/dev/null 2>&1; then
    (
        cd "${STAGING_DIR}"
        zip -rq "${ZIP_PATH}" "${PLUGIN_SLUG}"
    )
else
    echo "    (zip not found — using python3)"
    python3 - "${STAGING_DIR}" "${ZIP_PATH}" "${PLUGIN_SLUG}" <<'PY'
import os, sys, zipfile
from pathlib import Path
staging_dir = Path(sys.argv[1])
zip_path    = Path(sys.argv[2])
slug        = sys.argv[3]
root        = staging_dir / slug
with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
    for dirpath, _, filenames in os.walk(root):
        for name in filenames:
            full = Path(dirpath) / name
            zf.write(full, full.relative_to(staging_dir).as_posix())
PY
fi

rm -rf "${STAGING_DIR}"

echo "==> Verifying production ZIP"
python3 "${SCRIPT_DIR}/lib/verify-release-zip.py" "${ZIP_PATH}" "${PLUGIN_SLUG}"

echo "==> ZIP summary"
python3 - "${ZIP_PATH}" "${PLUGIN_SLUG}" <<'PY'
import sys, zipfile
from collections import Counter
zip_path, slug = sys.argv[1], sys.argv[2]
prefix = f"{slug}/"
with zipfile.ZipFile(zip_path) as zf:
    names = sorted(n for n in zf.namelist() if n.startswith(prefix))
    tops  = Counter(n[len(prefix):].split("/")[0] for n in names if n != prefix)
    print(f"    Path:  {zip_path}")
    print(f"    Total: {len(names)} entries")
    print("    Top-level dirs under plugin root:")
    for key in sorted(tops):
        print(f"      {key}/  ({tops[key]} files)")
PY

echo "==> Build complete: ${ZIP_PATH}"
