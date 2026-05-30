#!/usr/bin/env bash
#
# Syntax-check every PHP file in the AI module with `php -l`, and validate
# manifest.json. Run from the module root on the server:
#
#     bash lint.sh
#
# Use a specific PHP (e.g. the one your Zabbix frontend runs) like this:
#
#     PHP_BIN=/usr/bin/php8.2 bash lint.sh
#
# Exit code 0 = everything OK, 1 = at least one file failed, 2 = setup problem.

set -uo pipefail

# Module root = the directory this script lives in (works from any cwd).
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT" || exit 2

PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "ERROR: '$PHP_BIN' not found on PATH." >&2
    echo "       Set PHP_BIN to your php binary, e.g.: PHP_BIN=/usr/bin/php8.2 bash lint.sh" >&2
    exit 2
fi

echo "PHP    : $("$PHP_BIN" -v 2>/dev/null | head -n1)"
echo "Module : $ROOT"
echo "============================================================"

pass=0
fail=0
failed_files=()

# Lint every .php file under the module, skipping hidden dirs (.git, .claude).
while IFS= read -r -d '' f; do
    if out="$("$PHP_BIN" -l "$f" 2>&1)"; then
        pass=$((pass + 1))
        # Uncomment the next line if you want to see every passing file:
        # echo "OK    ${f#./}"
    else
        fail=$((fail + 1))
        failed_files+=("${f#./}")
        echo "FAIL  ${f#./}"
        printf '%s\n' "$out" | sed 's/^/        /'
    fi
done < <(find . -type f -name '*.php' -not -path '*/.*/*' -print0 | sort -z)

echo "------------------------------------------------------------"

# manifest.json is not PHP, but a broken manifest breaks the whole module.
if [ -f manifest.json ]; then
    if "$PHP_BIN" -r '$d = json_decode(file_get_contents("manifest.json")); exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);' 2>/dev/null; then
        echo "OK    manifest.json (valid JSON)"
    else
        msg="$("$PHP_BIN" -r '$d = json_decode(file_get_contents("manifest.json")); echo json_last_error_msg();' 2>/dev/null)"
        echo "FAIL  manifest.json (invalid JSON: ${msg})"
        fail=$((fail + 1))
        failed_files+=("manifest.json")
    fi
fi

echo "============================================================"
echo "PHP files checked: passed=${pass}  failed=${fail}"

if [ "$fail" -gt 0 ]; then
    echo
    echo "Files with problems:"
    for f in "${failed_files[@]}"; do
        echo "  - ${f}"
    done
    echo
    echo "RESULT: FAILED"
    exit 1
fi

echo "RESULT: OK — no syntax errors."
exit 0
