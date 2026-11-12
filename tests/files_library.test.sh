#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Files libraries (a directory with a .filesfolder marker): subfolders, uploads into them,
# and deletes — and what none of the three may reach.
#
#   - listing: folders first, then files, natural order; dot-entries hidden; `sub` lists a
#     subfolder, and a `..` in it is refused rather than resolved;
#   - create_library_folder: makes a subfolder, refuses a duplicate unless exist_ok, refuses
#     dot-names and separators;
#   - upload_to_folder: into a subfolder, creating missing ones (a dropped desktop folder),
#     never overwriting ("name (1).ext"), refusing dot-names (.htaccess, the marker);
#   - delete_folder_file: a file, a folder recursively, never the library itself, never
#     outside a library, never into a Space the actor cannot read (it used to take a
#     PAGES_DIR-relative path and unlink it with no check at all);
#   - readers are refused all three writes;
#   - the indexers: a .md or .json stored in a library is not a page — no id, no search hit.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main; fixture_space Secret
fixture_users '{"users":[
  {"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
  {"uid":2,"name":"Ria","role":"editor","auth":"otp","email":"ria@example.com","spaces":["Main"]},
  {"uid":4,"name":"Rex","role":"reader","auth":"otp","email":"rex@example.com"},
  {"uid":9,"name":"Bot","role":"editor","is_ai":true,"service_token":"wk_ai_libtest","spaces":["Main"],
   "ai_config":{"provider":"openai","model":"gpt-4o"}}
]}'
ADMIN=$WIKI_ROOT/jar-admin; RIA=$WIKI_ROOT/jar-ria; REX=$WIKI_ROOT/jar-rex
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin"
fixture_login "$RIA"   "uid=2&name=Ria&role=editor&spaces=Main"
fixture_login "$REX"   "uid=4&name=Rex&role=reader"

fixture_page 'Main/Main.md'  '# Main'
fixture_page 'Secret/Keep.md' '# Keep'
LIB="$WIKI_PAGES/Main/Docs"
mkdir -p "$LIB"; : > "$LIB/.filesfolder"
printf 'b' > "$LIB/b.txt"; printf 'a10' > "$LIB/a10.txt"; printf 'a2' > "$LIB/a2.txt"
mkdir -p "$WIKI_PAGES/Secret/Vault"; : > "$WIKI_PAGES/Secret/Vault/.filesfolder"
printf 'secret' > "$WIKI_PAGES/Secret/Vault/plan.txt"

TMP=$WIKI_ROOT/up; mkdir -p "$TMP"
printf 'hello' > "$TMP/report.pdf"
printf 'deny'  > "$TMP/.htaccess"

field() { printf '%s' "$1" | python3 -c 'import json,sys; r=json.load(sys.stdin); print(eval(sys.argv[1]))' "$2"; }
names() { field "$1" '" ".join(e["type"][0]+":"+e["name"] for e in r.get("data", []))'; }
upload() {  # jar sub file
    curl -s -b "$1" -c "$1" --max-time 15 -F "folder_path=Docs" -F "sub=$2" -F "file=@$3" \
        "$WIKI_URL/api.php?action=upload_to_folder&space=Main"
}

section 'creating subfolders'
R=$(post_as "$RIA" 'api.php?action=create_library_folder&space=Main' 'folder_path=Docs&name=Reports')
assert_eq 'an editor creates one'                      True "$(field "$R" 'r["success"]')"
assert_file_exists 'it is on disk'                     "$LIB/Reports"
R=$(post_as "$RIA" 'api.php?action=create_library_folder&space=Main' 'folder_path=Docs&sub=Reports&name=2026')
assert_file_exists 'and one inside it'                 "$LIB/Reports/2026"
R=$(post_as "$RIA" 'api.php?action=create_library_folder&space=Main' 'folder_path=Docs&name=Reports')
assert_eq 'a duplicate is refused'                     False "$(field "$R" 'r["success"]')"
R=$(post_as "$RIA" 'api.php?action=create_library_folder&space=Main' 'folder_path=Docs&name=Reports&exist_ok=1')
assert_eq '…unless exist_ok (a dropped folder merges)' True "$(field "$R" 'r["success"]')"
R=$(post_as "$RIA" 'api.php?action=create_library_folder&space=Main' 'folder_path=Docs&name=.git')
assert_eq 'a dot-name is refused'                      False "$(field "$R" 'r["success"]')"
assert_file_missing '…and not created'                 "$LIB/.git"
R=$(post_as "$RIA" 'api.php?action=create_library_folder&space=Main' 'folder_path=Docs&sub=..%2F..&name=Escaped')
assert_eq 'a .. in sub is refused'                     False "$(field "$R" 'r["success"]')"
assert_file_missing '…and nothing escapes'             "$WIKI_PAGES/Escaped"
R=$(post_as "$RIA" 'api.php?action=create_library_folder&space=Main' 'folder_path=Main.md&name=X')
assert_eq 'a non-library is refused'                   False "$(field "$R" 'r["success"]')"

section 'listing'
R=$(get_as "$RIA" 'api.php?action=list_folder_files&space=Main&folder_path=Docs')
assert_eq 'folders first, then files in natural order' 'd:Reports f:a2.txt f:a10.txt f:b.txt' "$(names "$R")"
assert_not_contains 'the marker is hidden'             '.filesfolder' "$R"
R=$(get_as "$RIA" 'api.php?action=list_folder_files&space=Main&folder_path=Docs&sub=Reports')
assert_eq 'a subfolder lists its own contents'         'd:2026' "$(names "$R")"
assert_eq '…with library-relative rel'                 'Reports/2026' "$(field "$R" 'r["data"][0]["rel"]')"
R=$(get_as "$RIA" 'api.php?action=list_folder_files&space=Main&folder_path=Docs&sub=..')
assert_eq 'a .. sub is refused, not resolved'          False "$(field "$R" 'r["success"]')"

section 'uploading'
R=$(upload "$RIA" 'Reports' "$TMP/report.pdf")
assert_eq 'into a subfolder'                           True "$(field "$R" 'r["success"]')"
assert_file_exists '…lands there'                      "$LIB/Reports/report.pdf"
R=$(upload "$RIA" 'Reports' "$TMP/report.pdf")
assert_eq 'a second copy is renamed, not overwritten'  'report (1).pdf' "$(field "$R" 'r["name"]')"
assert_eq '…and says so'                               True "$(field "$R" 'r["renamed"]')"
R=$(upload "$RIA" 'Dropped/Inner' "$TMP/report.pdf")
assert_file_exists 'missing subfolders are created'    "$LIB/Dropped/Inner/report.pdf"
R=$(upload "$RIA" '' "$TMP/.htaccess")
assert_eq 'a dot-name upload is refused'               False "$(field "$R" 'r["success"]')"
assert_file_missing '…and not written'                 "$LIB/.htaccess"
R=$(upload "$RIA" '../..' "$TMP/report.pdf")
assert_eq 'a .. sub upload is refused'                 False "$(field "$R" 'r["success"]')"
assert_file_missing '…and nothing escapes'             "$WIKI_PAGES/report.pdf"

section 'deleting'
R=$(post_as "$RIA" 'api.php?action=delete_folder_file&space=Main' 'folder_path=Docs&rel=b.txt')
assert_eq 'a file'                                     True "$(field "$R" 'r["success"]')"
assert_file_missing '…is gone'                         "$LIB/b.txt"
R=$(post_as "$RIA" 'api.php?action=delete_folder_file&space=Main' 'folder_path=Docs&rel=Dropped')
assert_eq 'a folder with contents'                     True "$(field "$R" 'r["success"]')"
assert_file_missing '…is gone, recursively'            "$LIB/Dropped"
R=$(post_as "$RIA" 'api.php?action=delete_folder_file&space=Main' 'folder_path=Docs&rel=')
assert_eq 'the library itself is refused'              False "$(field "$R" 'r["success"]')"
assert_file_exists '…and still there'                  "$LIB/.filesfolder"
R=$(post_as "$RIA" 'api.php?action=delete_folder_file&space=Main' 'folder_path=Docs&rel=.filesfolder')
assert_eq 'the marker is refused'                      False "$(field "$R" 'r["success"]')"
assert_file_exists '…and still there'                  "$LIB/.filesfolder"
R=$(post_as "$RIA" 'api.php?action=delete_folder_file&space=Main' 'folder_path=Docs&rel=..%2FMain.md')
assert_eq 'a .. rel is refused'                        False "$(field "$R" 'r["success"]')"
assert_file_exists '…and the page survives'            "$WIKI_PAGES/Main/Main.md"
R=$(post_as "$RIA" 'api.php?action=delete_folder_file&space=Main' 'folder_path=&rel=Main.md')
assert_eq 'outside any library is refused'             False "$(field "$R" 'r["success"]')"
assert_file_exists '…and the page survives'            "$WIKI_PAGES/Main/Main.md"
# The old action took a PAGES_DIR-relative path, so this deleted another Space's file.
R=$(post_as "$RIA" 'api.php?action=delete_folder_file&space=Main' 'path=Secret/Vault/plan.txt')
assert_file_exists 'the old path form reaches nothing' "$WIKI_PAGES/Secret/Vault/plan.txt"
R=$(post_as "$RIA" 'api.php?action=delete_folder_file' 'folder_path=Secret/Vault&rel=plan.txt')
assert_eq 'a Space the actor cannot read is refused'   False "$(field "$R" 'r["success"]')"
assert_file_exists '…and its file survives'            "$WIKI_PAGES/Secret/Vault/plan.txt"
R=$(post_as "$ADMIN" 'api.php?action=delete_folder_file' 'folder_path=Secret/Vault&rel=plan.txt')
assert_eq 'positive control: the admin may'           True "$(field "$R" 'r["success"]')"

section 'not pages'
printf '# Stored note\n\nzebracornword\n' > "$LIB/Reports/Stored.md"
printf '{"k":"zebracornword"}' > "$LIB/data.json"
fixture_page 'Main/Real.md' '# Real

zebracornword in a real page.'
# A stale id from before libraries were skipped: a rebuild must drop it.
python3 - "$WIKI_PAGES/Main/index.json" <<'PY'
import json, sys
p = sys.argv[1]
try: d = json.load(open(p))
except Exception: d = {}
d['999111'] = {'path': 'Docs/old.md', 'tags': []}
json.dump(d, open(p, 'w'))
PY
printf '# Old\n' > "$LIB/old.md"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Main' > /dev/null
IDX=$(cat "$WIKI_PAGES/Main/index.json")
assert_contains 'positive control: a real page has an id' 'Real.md'           "$IDX"
assert_not_contains 'a .md in a library has none'         'Stored.md'         "$IDX"
assert_not_contains 'nor a .json'                         'data.json'         "$IDX"
assert_not_contains 'and a stale one is dropped'          'Docs/old.md'       "$IDX"
S=$(get_as "$ADMIN" 'api.php?action=search&space=Main&query=zebracornword')
assert_contains 'positive control: search finds the page' 'Real.md'           "$S"
assert_not_contains 'but not the stored file'             'Stored.md'         "$S"

section 'serving: open vs download'
printf '<script>alert(1)</script>' > "$LIB/page.html"
printf 'q' > "$LIB/we\"ird.txt"
hdr() { curl -s -D - -o /dev/null -b "$1" --max-time 15 "$WIKI_URL/$2" | tr -d '\r'; }
H=$(hdr "$RIA" 'getfile.php?path=Main%2FDocs%2Fa2.txt')
assert_contains 'open is inline'                         'Content-Disposition: inline'     "$H"
assert_contains '…and not sniffed'                       'X-Content-Type-Options: nosniff' "$H"
assert_not_contains '…and a plain file is not sandboxed' 'sandbox'                         "$H"
H=$(hdr "$RIA" 'getfile.php?path=Main%2FDocs%2Fa2.txt&download=1')
assert_contains 'download=1 is an attachment'            'Content-Disposition: attachment' "$H"
H=$(hdr "$RIA" 'getfile.php?path=Main%2FDocs%2Fpage.html')
assert_contains 'HTML opened inline runs sandboxed'      'Content-Security-Policy: sandbox' "$H"
H=$(hdr "$RIA" 'getfile.php?path=Main%2FDocs%2Fwe%22ird.txt')
assert_contains 'a quote in the name cannot end the header' 'filename="we_ird.txt"; filename*=UTF-8'"''"'we%22ird.txt' "$H"

section 'nothing is created inside a library'
# Wiki content never goes in or below a files library: every route that creates, saves,
# moves or copies is refused there, with a positive control beside the library.
lib_refused() { printf '%s' "$1" | grep -q 'files library' && echo refused || echo "allowed: $1"; }
assert_eq 'a page in the library'                       refused "$(lib_refused "$(post_as "$RIA" 'api.php?action=create_file&space=Main' 'path=Docs/page.md')")"
assert_file_missing '…is not written'                   "$LIB/page.md"
assert_eq 'a page deeper inside it'                     refused "$(lib_refused "$(post_as "$RIA" 'api.php?action=create_file&space=Main' 'path=Docs/Reports/deep.md')")"
assert_eq 'a wiki folder in it'                         refused "$(lib_refused "$(post_as "$RIA" 'api.php?action=create_folder&space=Main' 'path=Docs/Sub')")"
assert_eq 'a list in it'                                refused "$(lib_refused "$(post_as "$RIA" 'api.php?action=create_list&space=Main' 'path=Docs/l.list')")"
assert_eq 'another library in it'                       refused "$(lib_refused "$(post_as "$RIA" 'api.php?action=create_filesfolder&space=Main' 'path=Docs/Inner')")"
assert_eq 'saving a page into it'                       refused "$(lib_refused "$(curl -s -b "$RIA" -X POST --data-binary '# x' "$WIKI_URL/api.php?action=save&space=Main&file=Docs/saved.md")")"
assert_file_missing '…is not written'                   "$LIB/saved.md"
assert_eq 'moving a page into it'                       refused "$(lib_refused "$(post_as "$RIA" 'api.php?action=move&space=Main' 'old_path=Main.md&new_path=Docs/Main.md')")"
assert_file_exists '…leaves the page where it was'      "$WIKI_PAGES/Main/Main.md"
assert_eq 'copying a page into it'                      refused "$(lib_refused "$(post_as "$RIA" 'api.php?action=copy_page&space=Main' 'source_path=Main.md&new_path=Docs/Copy.md')")"
printf '# up\n' > "$TMP/up.md"
assert_eq 'dropping a Markdown page on it'              refused "$(lib_refused "$(curl -s -b "$RIA" -F folder=Docs -F "file=@$TMP/up.md" "$WIKI_URL/api.php?action=upload_page&space=Main")")"
assert_contains 'positive control: a page beside it'    '"success":true' "$(post_as "$RIA" 'api.php?action=create_file&space=Main' 'path=Beside.md')"
assert_file_exists '…is written'                        "$WIKI_PAGES/Main/Beside.md"
assert_contains 'and the library still takes its own folders' '"success":true' "$(post_as "$RIA" 'api.php?action=create_library_folder&space=Main' 'folder_path=Docs&name=Allowed')"
mcp_call() { curl -s -X POST -H 'Authorization: Bearer wk_ai_libtest' -H 'Content-Type: application/json' \
  -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}" "$WIKI_URL/mcp.php?space=Main"; }
assert_contains 'an AI user cannot write a page into it'     'inside a files library' "$(mcp_call wiki_write_page '{"path":"Docs/ai.md","content":"# AI"}')"
assert_file_missing '…nothing is written'               "$LIB/ai.md"
assert_contains 'nor rename a page into it'              'inside a files library' "$(mcp_call wiki_rename_page '{"path":"Beside.md","new_path":"Docs/Beside.md"}')"
assert_contains 'positive control: an AI page beside it' 'Page created' "$(mcp_call wiki_write_page '{"path":"AiBeside.md","content":"# AI"}')"
assert_file_exists '…is written'                        "$WIKI_PAGES/Main/AiBeside.md"

section 'readers'
R=$(post_as "$REX" 'api.php?action=create_library_folder&space=Main' 'folder_path=Docs&name=ByReader')
assert_file_missing 'cannot create a folder'           "$LIB/ByReader"
R=$(upload "$REX" '' "$TMP/report.pdf")
assert_file_missing 'cannot upload'                    "$LIB/report.pdf"
R=$(post_as "$REX" 'api.php?action=delete_folder_file&space=Main' 'folder_path=Docs&rel=a2.txt')
assert_file_exists 'cannot delete'                     "$LIB/a2.txt"
R=$(get_as "$REX" 'api.php?action=list_folder_files&space=Main&folder_path=Docs')
assert_contains 'positive control: can list'           'a2.txt' "$R"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
