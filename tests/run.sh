#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Run every tests/*.test.sh. Each file starts its own throwaway wiki and cleans up after
# itself, so they are independent and can be run singly:
#
#   tests/run.sh                      everything
#   tests/security_spaces.test.sh     just that one

set -uo pipefail
cd "$(dirname "$0")"

command -v php     >/dev/null || { echo "php not found"     >&2; exit 127; }
command -v curl    >/dev/null || { echo "curl not found"    >&2; exit 127; }
command -v python3 >/dev/null || { echo "python3 not found" >&2; exit 127; }

printf 'PHP %s\n' "$(php -r 'echo PHP_VERSION;')"

failed=0
for t in *.test.sh; do
    [ -e "$t" ] || continue
    printf '\n\033[1m%s\033[0m\n' "$t"
    bash "$t" || failed=$((failed + 1))
done

printf '\n'
if [ "$failed" -eq 0 ]; then
    printf '\033[32mall suites passed\033[0m\n'
else
    printf '\033[31m%d suite(s) failed\033[0m\n' "$failed"
fi
exit $(( failed > 0 ))
