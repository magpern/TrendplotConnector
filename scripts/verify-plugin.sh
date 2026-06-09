#!/usr/bin/env bash
#
# Verify plugin health locally.
#
# Usage:
#   bash scripts/verify-plugin.sh              # run all checks
#   bash scripts/verify-plugin.sh --lint-only  # PHP syntax lint only
#   bash scripts/verify-plugin.sh --brand-check-only  # forbidden brand check only
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

LINT_ONLY=false
BRAND_ONLY=false
for arg in "$@"; do
    case "${arg}" in
        --lint-only)        LINT_ONLY=true ;;
        --brand-check-only) BRAND_ONLY=true ;;
    esac
done

ERRORS=0

# ---------------------------------------------------------------------------
# 1. PHP syntax lint
# ---------------------------------------------------------------------------
if [[ "${BRAND_ONLY}" == "false" ]]; then
    echo "==> PHP syntax lint"
    PHP_BIN="$(command -v php || true)"
    if [[ -z "${PHP_BIN}" ]]; then
        echo "    SKIP: php not found in PATH (install PHP 8.1+ to run lint locally)"
    else
        while IFS= read -r -d '' file; do
            if ! "${PHP_BIN}" -l "${file}" > /dev/null 2>&1; then
                "${PHP_BIN}" -l "${file}" || true
                ERRORS=$((ERRORS + 1))
            fi
        done < <(find "${REPO_ROOT}" \
            -not -path "${REPO_ROOT}/.git/*" \
            -not -path "${REPO_ROOT}/vendor/*" \
            -not -path "${REPO_ROOT}/node_modules/*" \
            -name "*.php" -print0)
        echo "    OK: all PHP files pass syntax check"
    fi
fi

if [[ "${LINT_ONLY}" == "true" ]]; then
    exit "${ERRORS}"
fi

# ---------------------------------------------------------------------------
# 2. Forbidden brand check
# ---------------------------------------------------------------------------
if [[ "${LINT_ONLY}" == "false" ]]; then
    echo "==> Forbidden brand check"
    BRAND_HITS="$(
        grep -RIni \
            --exclude-dir=.git \
            --exclude-dir=vendor \
            --exclude-dir=node_modules \
            --exclude-dir=builds \
            --exclude-dir=scripts \
            "biopentra" "${REPO_ROOT}" 2>/dev/null || true
    )"
    if [[ -n "${BRAND_HITS}" ]]; then
        echo "ERROR: forbidden brand term 'biopentra' found:" >&2
        echo "${BRAND_HITS}" >&2
        ERRORS=$((ERRORS + 1))
    else
        echo "    OK: no forbidden brand terms found"
    fi
fi

if [[ "${BRAND_ONLY}" == "true" ]]; then
    exit "${ERRORS}"
fi

# ---------------------------------------------------------------------------
# 3. Required files check
# ---------------------------------------------------------------------------
echo "==> Required files check"
REQUIRED_FILES=(
    "trendplot-connector.php"
    "readme.txt"
    "LICENSE"
    "uninstall.php"
    "src/autoload.php"
    "src/Plugin.php"
)
for f in "${REQUIRED_FILES[@]}"; do
    if [[ ! -f "${REPO_ROOT}/${f}" ]]; then
        echo "ERROR: required file missing: ${f}" >&2
        ERRORS=$((ERRORS + 1))
    fi
done
if [[ "${ERRORS}" -eq 0 ]]; then
    echo "    OK: all required files present"
fi

# ---------------------------------------------------------------------------
# 4. Version consistency check
# ---------------------------------------------------------------------------
echo "==> Version consistency check"
MAIN_FILE="${REPO_ROOT}/trendplot-connector.php"
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
if [[ "${HEADER_VERSION}" != "${CONST_VERSION}" ]]; then
    echo "ERROR: header Version (${HEADER_VERSION}) != TRENDPLOT_CONNECTOR_VERSION (${CONST_VERSION})" >&2
    ERRORS=$((ERRORS + 1))
else
    echo "    OK: version ${HEADER_VERSION} consistent"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
if [[ "${ERRORS}" -eq 0 ]]; then
    echo "==> All checks passed."
else
    echo "==> ${ERRORS} check(s) failed." >&2
    exit 1
fi
