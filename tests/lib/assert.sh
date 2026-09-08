# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Minimal assertions. Deliberately not a framework: these tests are black-box HTTP
# against a real server, so the only thing a framework would add here is installation.
#
# Every assertion prints one line and counts itself. A test file is a shell script;
# tests/run.sh collects the counts.

ASSERT_PASS=0
ASSERT_FAIL=0

_green() { printf '\033[32m%s\033[0m' "$1"; }
_red()   { printf '\033[31m%s\033[0m' "$1"; }
_dim()   { printf '\033[2m%s\033[0m'  "$1"; }

_pass() { ASSERT_PASS=$((ASSERT_PASS + 1)); printf '  %s %s\n' "$(_green ✓)" "$1"; }
_fail() {
    ASSERT_FAIL=$((ASSERT_FAIL + 1))
    printf '  %s %s\n' "$(_red ✗)" "$1"
    [ -n "${2:-}" ] && printf '      %s\n' "$(_dim "$2")"
    return 0
}

# assert_contains <label> <needle> <haystack>
assert_contains() {
    case "$3" in
        *"$2"*) _pass "$1" ;;
        *)      _fail "$1" "expected to contain: $2 — got: $(printf '%s' "$3" | tr -d '\n' | cut -c1-160)" ;;
    esac
}

# assert_not_contains <label> <needle> <haystack>
assert_not_contains() {
    case "$3" in
        *"$2"*) _fail "$1" "must NOT contain: $2 — got: $(printf '%s' "$3" | tr -d '\n' | cut -c1-160)" ;;
        *)      _pass "$1" ;;
    esac
}

# assert_eq <label> <expected> <actual>
assert_eq() {
    if [ "$2" = "$3" ]; then _pass "$1"; else _fail "$1" "expected [$2], got [$3]"; fi
}

# assert_file_missing <label> <path> — for "the write must not have happened"
assert_file_missing() {
    if [ -e "$2" ]; then _fail "$1" "file exists but should not: $2"; else _pass "$1"; fi
}

# assert_file_exists <label> <path>
assert_file_exists() {
    if [ -e "$2" ]; then _pass "$1"; else _fail "$1" "file missing: $2"; fi
}

section() { printf '\n  %s\n' "$(_dim "$1")"; }
