#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# What a destructive action does with a path that is missing, empty, or normalises away.
#
# `delete` is recursive, and every relative path is resolved against the Space directory, so
# a path that reduces to nothing resolves to the Space *root*. Before the guard,
# `?action=delete` with no `path` at all deleted an entire Space and answered
# `{"success":true,"message":"Item deleted."}`.
#
# Note that sanitize_path() strips '..' to the empty string, so `path=..` and `path=/` arrive
# at the root by a different door than `path=` does. Each shape is asserted separately
# because each one reaches the guard along its own route, and each is paired with a real
# delete as a positive control — a request rejected for some unrelated reason would
# otherwise look exactly like a request the guard caught.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_users '{"users":[{"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&name=Admin&role=admin"

seed() {
    mkdir -p "$WIKI_PAGES/Main/Folder"
    printf '# Keep\n'  > "$WIKI_PAGES/Main/Keep.md"
    printf '# Inner\n' > "$WIKI_PAGES/Main/Folder/Inner.md"
}
survivors() { ls "$WIKI_PAGES/Main" 2>/dev/null | tr '\n' ' '; }

section 'a path that resolves to the Space root is refused'
for probe in "" "path=" "path=.." "path=/" "path=./" "path=../.."; do
    seed
    label=${probe:-<no parameter at all>}
    r=$(post_as "$JAR" 'api.php?action=delete&space=Main' "${probe:-x=1}")
    assert_contains    "refused: $label"          '"success":false'          "$r"
    assert_file_exists "Keep.md survives: $label" "$WIKI_PAGES/Main/Keep.md"
    assert_file_exists "the Space survives: $label" "$WIKI_PAGES/Main/Folder/Inner.md"
done

section 'positive control — a real delete still works'
seed
r=$(post_as "$JAR" 'api.php?action=delete&space=Main' 'path=Keep.md')
assert_contains    "a named file is deleted" '"success":true' "$r"
assert_file_missing "and is gone"            "$WIKI_PAGES/Main/Keep.md"

section 'positive control — a folder delete is still recursive'
seed
r=$(post_as "$JAR" 'api.php?action=delete&space=Main' 'path=Folder')
assert_contains     "a named folder is deleted" '"success":true' "$r"
assert_file_missing "with its contents"         "$WIKI_PAGES/Main/Folder/Inner.md"

section 'the other destructive actions fail safe on the same input'
# They already did; asserted so a future refactor cannot quietly give one of them the
# behaviour delete used to have.
for act in move copy_page; do
    seed
    post_as "$JAR" "api.php?action=$act&space=Main" 'x=1' > /dev/null
    assert_file_exists "$act leaves the Space intact" "$WIKI_PAGES/Main/Keep.md"
done

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
