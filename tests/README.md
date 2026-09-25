# Tests

Black-box tests that exercise the real application over HTTP. No framework and no new
dependencies: they need `php`, `curl` and `python3`, all of which are already required to
run or develop the wiki.

```bash
tests/run.sh                       # everything
tests/security_spaces.test.sh      # one suite
```

Each suite builds its own throwaway wiki — a copy of the working tree, a `config.php`
generated from `config.php.txt`, and content in a temp directory — starts `php -S` on a free
port, and removes it all on exit. That is the pattern CLAUDE.md already prescribes for
verifying a change by hand; this makes it reusable so checks accumulate.

Because the copy is of the **working tree**, not a git export, the tests run against what is
on disk right now, uncommitted changes included.

## Writing one

```bash
#!/usr/bin/env bash
set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1     # 'off' | 'otp' | 'oidc' | 'both'
trap fixture_stop EXIT

fixture_space Alpha
fixture_page  "Alpha/page.md" '# Hello'
fixture_users '{"users":[{"uid":1,"sub":"s1","name":"A","role":"admin","auth":"oidc"}]}'

JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&role=admin"

assert_contains "reads the page" "Hello" "$(get_as "$JAR" 'api.php?action=get&space=Alpha&file=page.md')"

exit $(( ASSERT_FAIL > 0 ))
```

Helpers: `get_as`, `post_as`, `status_as`, `get_token`; `assert_contains`,
`assert_not_contains`, `assert_eq`, `assert_file_exists`, `assert_file_missing`, `section`.

`fixture_login` writes the session shape `auth.php` produces — an OIDC session carries `sub`,
an OTP session does not, which is load-bearing in several places. The shim it posts to is
generated into the temp copy at run time and is never part of the repository or a release.

## Two rules learned the hard way

**Give destructive assertions their own targets.** A delete that succeeds against broken code
removes the file a later read asserts on, so the read finds nothing and *passes*. The suite
then goes quiet exactly when it should shout.

**Pair every denial with a positive control.** `save` takes its path in the query string and
its body as form data; sending both as form data makes the request fail for an unrelated
reason, and the denial assertion passes without ever reaching the guard. The control proves
the call is well formed before its refusal means anything.

## Verifying a security suite

A security test that passes against fixed code proves nothing. Copy the tree, re-introduce
the vulnerability, and confirm the suite goes red — and that **only the attack assertions**
do. If legitimate-access assertions also fail, the suite cannot tell a regression from
collateral damage.

`security_spaces.test.sh` was checked this way: 13 attack assertions fail against the
pre-2026.9.3 behaviour while all 15 legitimate-access assertions stay green.

`destructive_paths.test.sh` likewise: removing the one-line guard in `delete` turns 17
assertions red and leaves the positive controls green. Its subject is worth restating —
`?action=delete` with no `path` at all used to delete an **entire Space** and answer
`{"success":true}`, because every relative path resolves against the Space directory and a
path that reduces to nothing resolves to its root.

`frontmatter.test.sh` uses the same method on a feature that is not a security one, because
its failure mode is equally quiet. Mutating `wiki_fm_body()` into a no-op turns 13 assertions
red — one per surface that reads a page body — and mutating `wiki_fm_preserve()` turns 5 red,
the ones asserting that a save keeps the block. Writing it that way found a real defect the
green suite had not: `graph.php` was stripping via an undefined `$path`, so the graph and the
backlink list disagreed about whether a `[[link]]` in someone's metadata was a link.

`realtime.test.sh` grew an external-change section, and writing it hit three traps worth
repeating, each of which silently leaves the publish log empty rather than failing loudly:

- **The suite kills the stub hub part-way through**, on purpose, to prove a publish failure
  does not break a write — and never restarts it. Anything asserting on publishes has to sit
  *above* that point.
- **A scan is debounced** (`INDEX_SYNC_INTERVAL_SECONDS`, 30 s; a configured 0 is clamped up
  to it). Rewriting config.php does not help, because it is opcached and the new value is
  not in force for the very next request — drop the stamp file the debounce reads instead.
- **Drift is `mtime > the index's updated stamp`, at 1-second resolution.** A file written
  in the same second as its index entry is not newer, so it is not drift. The same
  resolution trap as the open-page watcher and the mentions marker.

Some things a suite cannot assert, and where the line is. `realtime.test.sh` checks what the
wiki *publishes* — the topics, the payload, and that every publish carries `private=on` —
against a stub that records each POST. Whether the hub then honours a subscriber's token is
upstream's contract, verified by hand against a real Mercure 1.0.2 hub rather than by
downloading 34 MB in CI. That check is worth repeating if the hub is ever upgraded: with
`private=on` removed, a user restricted to Space Main receives Space Bravo's change stream.

## Traps

- **Never `wait` with no arguments.** `fixture_start` runs the web server as a background
  job of the same shell, so bare `wait` blocks on `php -S` and the suite hangs forever.
  Record the PIDs you actually care about and wait on those.
- **`php -r 'code' foo=bar` does not set an environment variable** — it lands in `$argv`.
  Put the assignment before the command: `FOO=bar php -r '…'`.
- **Piping a suite through `tail` hides its progress**, which makes a hang look like a slow
  test. Redirect to a file instead while debugging one.
