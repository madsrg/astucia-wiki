#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# `state.currentPageType` is a closed set of strings shared by a dozen modules through one
# mutable object, with nothing to catch a typo — and a wrong comparison fails *silently*:
# the feature keyed on it simply never appears, which reads as a caching problem rather
# than a bug. That happened: the Metadata menu item was gated on `!== 'md'` while a
# Markdown page's type was the string `'file'`, so the button was hidden on every page
# there is, and the code looked right in review.
#
# So this is a static check rather than an HTTP one — the only kind that can catch it
# without driving a browser. It reads the modules and asserts that every string compared
# against currentPageType is one the code actually assigns.
#
# Note the deliberate near-miss: the **file tree's** node type is a *different* vocabulary
# with its own `'file'` (`api.php` emits it, and it is part of the documented REST response
# shape). Renaming that would break external integrators, so the two must not be conflated
# — which is exactly why a typo here is so easy to make.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh

section 'every currentPageType comparison uses a value that is assigned'
out=$(cd .. && python3 - <<'PY'
import glob, re, sys

files = sorted(glob.glob('modules/**/*.js', recursive=True)) + ['script.js']
assigned, compared, watched = set(), {}, []
for f in files:
    src = open(f, encoding='utf-8').read()
    # Assignments, including the chained ternary in loadPage: take every literal on a line
    # that writes the field.
    for line in src.splitlines():
        if re.search(r'state\.currentPageType\s*=(?!=)', line):
            assigned.update(re.findall(r"'([a-z]+)'", line))
    # Comparisons.
    for m in re.finditer(r"currentPageType\s*[=!]==\s*'([^']*)'", src):
        compared.setdefault(m.group(1), []).append(f)
    # A bare list of types is the same hazard as a comparison.
    for m in re.finditer(r"WATCHED_TYPES\s*=\s*\[([^\]]*)\]", src):
        watched += re.findall(r"'([^']*)'", m.group(1))

print('ASSIGNED=' + ','.join(sorted(assigned)))
print('COMPARED=' + ','.join(sorted(compared)))
print('WATCHED='  + ','.join(sorted(watched)))
for v, where in sorted(compared.items()):
    if v not in assigned:
        print(f'UNKNOWN={v} in {",".join(sorted(set(where)))}')
for v in sorted(set(watched)):
    if v not in assigned:
        print(f'UNKNOWN_WATCHED={v}')
PY
)
printf '  %s\n' "$(_dim "$(printf '%s' "$out" | grep '^ASSIGNED=')")"

assert_not_contains "no comparison against an unassigned value" 'UNKNOWN=' "$out"
assert_not_contains "no watched type that is never assigned"     'UNKNOWN_WATCHED=' "$out"

# Pin the set itself, so adding a content type is a deliberate edit here too — this is the
# list every one of those comparisons is checked against, and it is worthless if it can
# grow by accident.
assert_contains "the set is the one we expect" \
    'ASSIGNED=chat,diagram,filesfolder,folder,json,list,md,search' "$out"
assert_contains "a Markdown page's type is md" 'md' \
    "$(printf '%s' "$out" | sed -n 's/^COMPARED=//p')"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
