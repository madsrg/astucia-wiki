#!/bin/bash
# Push docker/hub-description.md to the Docker Hub repository's Description field.
#
#   ./docker/push-hub-description.sh                        # madsrotwitt/astucia-wiki
#   REPO=you/astucia-wiki ./docker/push-hub-description.sh
#   DOCKERHUB_USER=you DOCKERHUB_TOKEN=dckr_pat_… ./docker/push-hub-description.sh
#
# The image push and this are separate operations on Docker Hub: `docker push` moves layers
# and never touches the repository's text, so the description silently keeps describing an
# older image unless something updates it. That "something" was a manual paste, which is
# why the page sat a release behind.
#
# Credentials come from DOCKERHUB_USER/DOCKERHUB_TOKEN when set, otherwise from the
# existing `docker login` in ~/.docker/config.json — the same credential that pushed the
# image, so this adds no new secret to manage. A credsStore (keychain/pass) keeps no
# password in that file, so in that case the two variables are required.
set -euo pipefail

cd "$(dirname "$0")/.."

REPO="${REPO:-madsrotwitt/astucia-wiki}"
BODY_FILE="${BODY_FILE:-docker/hub-description.md}"

[ -f "$BODY_FILE" ] || { echo "ERROR: $BODY_FILE not found" >&2; exit 1; }

python3 - "$REPO" "$BODY_FILE" <<'PY'
import base64, json, os, sys, urllib.error, urllib.request

repo, body_file = sys.argv[1], sys.argv[2]
namespace, _, name = repo.partition('/')
if not name:
    sys.exit(f"ERROR: REPO must be namespace/name, got {repo!r}")

body = open(body_file, encoding='utf-8').read()
# Hub's own cap. Failing here beats a truncated page nobody notices.
if len(body) > 25000:
    sys.exit(f"ERROR: {body_file} is {len(body)} characters; Docker Hub allows 25000")

user = os.environ.get('DOCKERHUB_USER')
secret = os.environ.get('DOCKERHUB_TOKEN')
source = 'DOCKERHUB_USER/DOCKERHUB_TOKEN'
if not (user and secret):
    cfg = os.path.expanduser('~/.docker/config.json')
    try:
        auths = json.load(open(cfg)).get('auths', {})
    except OSError:
        auths = {}
    entry = auths.get('https://index.docker.io/v1/') or auths.get('index.docker.io') or {}
    raw = entry.get('auth')
    if not raw:
        sys.exit("ERROR: no Docker Hub credential found. Either `docker login`, or set\n"
                 "       DOCKERHUB_USER and DOCKERHUB_TOKEN (required when a credsStore\n"
                 "       keeps the password outside config.json).")
    user, _, secret = base64.b64decode(raw).decode('utf-8').partition(':')
    source = cfg

def call(url, data=None, headers=None, method=None):
    req = urllib.request.Request(
        url,
        data=json.dumps(data).encode('utf-8') if data is not None else None,
        headers={'Content-Type': 'application/json', **(headers or {})},
        method=method,
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read().decode('utf-8') or '{}')
    except urllib.error.HTTPError as e:
        detail = e.read().decode('utf-8', 'replace')[:400]
        return e.code, {'_error': detail}

print(f"  repo        {repo}")
print(f"  description {body_file} ({len(body)} characters)")
print(f"  credential  {user} (from {source})")

status, payload = call('https://hub.docker.com/v2/users/login/',
                       {'username': user, 'password': secret})
token = payload.get('token')
if not token:
    sys.exit(f"ERROR: Hub login failed (HTTP {status}): {payload.get('_error', payload)}")
print("  login       ok")

# Hub has accepted "JWT <token>" for years; newer deployments also take Bearer. Try both
# rather than guess, because a 401 here is indistinguishable from a bad credential.
url = f'https://hub.docker.com/v2/repositories/{namespace}/{name}/'
for scheme in ('JWT', 'Bearer'):
    status, payload = call(url, {'full_description': body},
                           {'Authorization': f'{scheme} {token}'}, method='PATCH')
    if status < 300:
        break
else:
    sys.exit(f"ERROR: update failed (HTTP {status}): {payload.get('_error', payload)}")

# A 200 is not proof: read it back and compare, or a silently-ignored field looks like success.
status, got = call(url, headers={'Authorization': f'JWT {token}'})
live = (got or {}).get('full_description') or ''
if live.strip() != body.strip():
    sys.exit(f"ERROR: the page does not match what was sent "
             f"({len(live)} characters live, {len(body)} sent)")
print(f"  updated     {len(live)} characters live, byte-identical to {body_file}")
print(f"  view        https://hub.docker.com/r/{namespace}/{name}")
PY
