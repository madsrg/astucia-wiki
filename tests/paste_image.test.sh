#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Pasting an image into the Markdown editor.
#
# The clipboard has no filename in it: every screenshot arrives as "image.png", so the
# whole feature turns on `upload_attachment` not overwriting what is already there. The
# browser half (a `paste` listener that uploads the blob and writes the link) has no HTTP
# surface at all and is not covered here; what is covered is the naming contract it
# depends on, which is the part that silently destroys data when it is wrong.
#
# Two things the assertions are careful about:
#   - the *earlier* files must still be on disk afterwards, not merely a new name in the
#     response. A response saying "image2.png" while image.png was clobbered anyway would
#     pass a name-only check.
#   - the counter is opt-in, so the attach button and the image lightbox still replace a
#     file on purpose. That is asserted too, or the flag could quietly become the default.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_users '{"users":[{"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
                         {"uid":2,"name":"Rita","role":"reader","auth":"otp","email":"rita@example.com"}]}'

fixture_page 'Main/Notes.md'   '# Notes'
fixture_page 'Main/Other.md'   '# Other'

JAR=$WIKI_ROOT/jar
JARR=$WIKI_ROOT/jar-reader
fixture_login "$JAR"  "uid=1&auth=otp&name=Admin&role=admin"
fixture_login "$JARR" "uid=2&auth=otp&name=Rita&role=reader"

# Three distinguishable payloads, so "was the first one overwritten" is answerable by
# reading the bytes back rather than by trusting a filename.
printf 'FIRST'  > "$WIKI_ROOT/a.png"
printf 'SECOND' > "$WIKI_ROOT/b.png"
printf 'THIRD'  > "$WIKI_ROOT/c.png"

UP=$WIKI_PAGES/Main/Notes.md.uploads

# paste <jar> <local file> <page> [extra form field]
paste() {
    curl -s -b "$1" -c "$1" --max-time 15 \
         -F "file=@$2;filename=image.png;type=image/png" \
         -F "page_path=$3" \
         ${4:+-F "$4"} \
         "$WIKI_URL/api.php?action=upload_attachment&space=Main"
}

section 'A pasted image is stored, and reports the name it was stored under'

r1=$(paste "$JAR" "$WIKI_ROOT/a.png" 'Notes.md' 'no_overwrite=1')
assert_contains "the upload succeeds"        '"success":true'        "$r1"
assert_contains "and names the file"         '"filename":"image.png"' "$r1"
assert_contains "which was not renamed"      '"renamed":false'       "$r1"
assert_file_exists "it is on disk"           "$UP/image.png"
assert_eq "with the bytes that were sent"    'FIRST' "$(cat "$UP/image.png")"

section 'A second paste does not overwrite the first'

r2=$(paste "$JAR" "$WIKI_ROOT/b.png" 'Notes.md' 'no_overwrite=1')
assert_contains "the counter starts at 2"    '"filename":"image2.png"' "$r2"
assert_contains "and it says so"             '"renamed":true'          "$r2"
assert_file_exists "the new file is there"   "$UP/image2.png"
assert_eq "holding the second image"         'SECOND' "$(cat "$UP/image2.png")"
assert_file_exists "the first still exists"  "$UP/image.png"
assert_eq "  and is untouched"               'FIRST'  "$(cat "$UP/image.png")"

r3=$(paste "$JAR" "$WIKI_ROOT/c.png" 'Notes.md' 'no_overwrite=1')
assert_contains "a third gets image3.png"    '"filename":"image3.png"' "$r3"
assert_eq "holding the third image"          'THIRD'  "$(cat "$UP/image3.png")"
assert_eq "the first is still untouched"     'FIRST'  "$(cat "$UP/image.png")"
assert_eq "so is the second"                 'SECOND' "$(cat "$UP/image2.png")"

section 'It lists as an ordinary attachment of that page'

lst=$(get_as "$JAR" 'api.php?action=list_attachments&space=Main&page_path=Notes.md')
assert_contains "the first is listed"  '"image.png"'  "$lst"
assert_contains "the second is listed" '"image2.png"' "$lst"
assert_contains "the third is listed"  '"image3.png"' "$lst"

section 'The counter is per page, not global'

r4=$(paste "$JAR" "$WIKI_ROOT/a.png" 'Other.md' 'no_overwrite=1')
assert_contains "another page starts over"   '"filename":"image.png"' "$r4"
assert_file_exists "in its own uploads dir"  "$WIKI_PAGES/Main/Other.md.uploads/image.png"

section 'Without the flag an upload still replaces, as the attach button expects'

r5=$(paste "$JAR" "$WIKI_ROOT/c.png" 'Notes.md')
assert_contains "the name is unchanged"   '"filename":"image.png"' "$r5"
assert_contains "and it is not a rename"  '"renamed":false'        "$r5"
assert_eq "the file was replaced"         'THIRD' "$(cat "$UP/image.png")"
assert_file_missing "no image4.png"       "$UP/image4.png"

section 'A reader cannot paste an image (upload_attachment is an edit action)'

denied=$(paste "$JARR" "$WIKI_ROOT/b.png" 'Notes.md' 'no_overwrite=1')
assert_not_contains "the upload is refused" '"success":true' "$denied"
assert_file_missing "and nothing was written" "$UP/image4.png"
# Positive control: the same request from an editor works, so the denial above was the
# guard and not a malformed request.
control=$(paste "$JAR" "$WIKI_ROOT/b.png" 'Notes.md' 'no_overwrite=1')
assert_contains "but an admin's does"       '"success":true' "$control"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
