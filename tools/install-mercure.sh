#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
# =============================================================================
# Install, configure and run the Mercure hub as a systemd service.
#
#   sudo ./tools/install-mercure.sh 0.24.2
#
# The wiki's realtime push needs a Mercure hub. The Docker image bundles one; this is
# the equivalent for a bare-metal install. It downloads the release, verifies its
# checksum, installs the binary, creates a locked-down service account, generates the
# shared key, writes the hub config and a hardened systemd unit, starts it and checks
# that it answers.
#
# Re-running with a different version upgrades in place. It never overwrites the key —
# regenerating it would silently break the wiki's config, so an existing key is kept.
#
# Everything it does is printed, including every path it writes.
#
# Optional environment overrides (the version stays the only argument):
#   MERCURE_PORT=3000        port the hub listens on, loopback only
#   MERCURE_USER=mercure     service account to create and run as
#   INSTALL_ROOT=            prefix every path; for staging or testing a build without
#                            touching the real system (skips useradd and systemctl)
# =============================================================================

set -euo pipefail

VERSION="${1:-}"
PORT="${MERCURE_PORT:-3000}"
SVC_USER="${MERCURE_USER:-mercure}"
ROOT="${INSTALL_ROOT:-}"

BIN_DIR="$ROOT/usr/local/bin"
ETC_DIR="$ROOT/etc/mercure"
LIC_DIR="$ROOT/usr/share/licenses/mercure"
UNIT="$ROOT/etc/systemd/system/mercure.service"
KEY_FILE="$ETC_DIR/mercure.key"
ENV_FILE="$ETC_DIR/mercure.env"
CADDYFILE="$ETC_DIR/Caddyfile"
SNIPPET="$ETC_DIR/wiki-config-snippet.php"

# ── output ───────────────────────────────────────────────────────────────────
if [ -t 1 ]; then B=$'\033[1m'; D=$'\033[2m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else B=; D=; G=; Y=; R=; N=; fi
step()  { printf '\n%s==> %s%s\n' "$B" "$1" "$N"; }
info()  { printf '    %s\n' "$1"; }
dim()   { printf '    %s%s%s\n' "$D" "$1" "$N"; }
ok()    { printf '    %s✓%s %s\n' "$G" "$N" "$1"; }
warn()  { printf '    %s!%s %s\n' "$Y" "$N" "$1"; }
die()   { printf '\n%sERROR:%s %s\n\n' "$R" "$N" "$1" >&2; exit 1; }
# Report a file exactly as it ended up on disk, so the log doubles as an inventory.
wrote() {
    local f=$1 what=${2:-}
    local meta; meta=$(stat -c '%a %U:%G %s bytes' "$f" 2>/dev/null || echo '?')
    printf '    %s✓%s wrote %s %s(%s)%s%s\n' "$G" "$N" "$f" "$D" "$meta" "$N" \
           "${what:+ — $what}"
}

usage() {
    cat <<EOF
Usage: sudo $0 <version>

  <version>   Mercure release to install, without the leading 'v'. For example:
                sudo $0 0.24.2
              Releases: https://github.com/dunglas/mercure/releases

Re-run with a newer version to upgrade. The shared key is never regenerated.
EOF
    exit 1
}

[ -n "$VERSION" ] || usage
case "$VERSION" in
    -h|--help) usage ;;
    v*) VERSION="${VERSION#v}" ;;   # tolerate 'v0.24.2'
esac
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]] || die "'$VERSION' is not a version like 0.24.2"

# ── 1. preflight ─────────────────────────────────────────────────────────────
step "Checking prerequisites"

STAGING=0
if [ -n "$ROOT" ]; then
    STAGING=1
    warn "INSTALL_ROOT=$ROOT — staging build; no service account, no systemd"
elif [ "$(id -u)" -ne 0 ]; then
    die "must run as root (installs to /usr/local/bin and /etc). Try: sudo $0 $VERSION"
fi

for c in curl tar sha256sum install; do
    command -v "$c" >/dev/null 2>&1 || die "required command not found: $c"
done
ok "curl, tar, sha256sum, install"

if [ "$STAGING" -eq 0 ]; then
    command -v systemctl >/dev/null 2>&1 || die "systemctl not found — this script installs a systemd service"
    ok "systemd present"
fi

case "$(uname -m)" in
    x86_64|amd64) ARCH=x86_64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) die "unsupported architecture: $(uname -m) (releases cover x86_64 and arm64)" ;;
esac
ok "architecture $(uname -m) → mercure_Linux_${ARCH}.tar.gz"

CURRENT=""
if [ -x "$BIN_DIR/mercure" ]; then
    CURRENT=$("$BIN_DIR/mercure" --version 2>/dev/null | head -1 || true)
    info "existing install: ${CURRENT:-unknown}"
fi

# ── 2. download and verify ───────────────────────────────────────────────────
step "Downloading Mercure $VERSION"

TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
dim "working in $TMP (removed on exit)"

BASE="https://github.com/dunglas/mercure/releases/download/v${VERSION}"
TARBALL="mercure_Linux_${ARCH}.tar.gz"

info "GET $BASE/$TARBALL"
curl -fsSL --retry 3 -o "$TMP/$TARBALL" "$BASE/$TARBALL" \
    || die "download failed — does release v$VERSION exist?"
ok "$(du -h "$TMP/$TARBALL" | cut -f1) downloaded"

info "GET $BASE/checksums.txt"
if curl -fsSL --retry 3 -o "$TMP/checksums.txt" "$BASE/checksums.txt"; then
    ( cd "$TMP" && grep " ${TARBALL}\$" checksums.txt > expected.txt \
        && sha256sum -c expected.txt >/dev/null ) \
        || die "checksum mismatch — refusing to install $TARBALL"
    ok "sha256 verified against the published checksums.txt"
else
    # Not fatal: older releases may not publish one. Say so rather than imply a check ran.
    warn "no checksums.txt for this release — the download could NOT be verified"
fi

info "extracting"
mkdir -p "$TMP/x" && tar -xzf "$TMP/$TARBALL" -C "$TMP/x"
[ -f "$TMP/x/mercure" ] || die "archive does not contain a 'mercure' binary"
ok "extracted $(cd "$TMP/x" && ls | tr '\n' ' ')"

# ── 3. stop a running service before replacing its binary ────────────────────
RESTART_AFTER=0
if [ "$STAGING" -eq 0 ] && systemctl is-active --quiet mercure 2>/dev/null; then
    step "Stopping the running hub before replacing it"
    systemctl stop mercure
    RESTART_AFTER=1
    ok "mercure.service stopped"
fi

# ── 4. install files ─────────────────────────────────────────────────────────
step "Installing files"

install -d -m 0755 "$BIN_DIR"
install -m 0755 "$TMP/x/mercure" "$BIN_DIR/mercure"
wrote "$BIN_DIR/mercure" "the hub binary"

# The hub is AGPL-3.0 and this is a redistribution of an unmodified upstream build, so
# its licence and copyright travel with it.
install -d -m 0755 "$LIC_DIR"
for f in LICENSE COPYRIGHT; do
    [ -f "$TMP/x/$f" ] && install -m 0644 "$TMP/x/$f" "$LIC_DIR/$f" && wrote "$LIC_DIR/$f"
done

# ── 5. service account ───────────────────────────────────────────────────────
step "Service account"

if [ "$STAGING" -eq 1 ]; then
    dim "skipped (staging): would create system user '$SVC_USER'"
elif id -u "$SVC_USER" >/dev/null 2>&1; then
    ok "user '$SVC_USER' already exists"
else
    # No login shell, no home, no password: it exists only to own the process.
    if command -v useradd >/dev/null 2>&1; then
        useradd --system --no-create-home --shell /usr/sbin/nologin "$SVC_USER"
    elif command -v adduser >/dev/null 2>&1; then
        adduser -S -H -s /sbin/nologin "$SVC_USER"      # busybox/alpine
    else
        die "neither useradd nor adduser found — create user '$SVC_USER' manually and re-run"
    fi
    ok "created system user '$SVC_USER' (no login shell, no home)"
fi

# ── 6. configuration directory and the shared key ────────────────────────────
step "Configuration"

install -d -m 0750 "$ETC_DIR"
info "config directory $ETC_DIR"

if [ -s "$KEY_FILE" ]; then
    # Never regenerate: the wiki's config.php holds a copy, and a new key here would
    # break every publish and every ticket with no obvious symptom beyond silence.
    ok "keeping the existing shared key at $KEY_FILE"
    KEY=$(cat "$KEY_FILE")
else
    if command -v openssl >/dev/null 2>&1; then
        KEY=$(openssl rand -hex 32)
    else
        KEY=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
    fi
    printf '%s' "$KEY" > "$KEY_FILE"
    chmod 0640 "$KEY_FILE"
    wrote "$KEY_FILE" "shared HS256 key, generated now"
fi

cat > "$ENV_FILE" <<EOF
# Generated by install-mercure.sh — the hub reads this via the systemd unit.
# Publisher and subscriber share one key: the wiki is both, and config.php holds a copy.
MERCURE_PUBLISHER_JWT_KEY=$KEY
MERCURE_SUBSCRIBER_JWT_KEY=$KEY
EOF
chmod 0640 "$ENV_FILE"
wrote "$ENV_FILE" "key as systemd EnvironmentFile"

cat > "$CADDYFILE" <<EOF
# Generated by install-mercure.sh for Astucia Wiki.
#
# Deliberately NOT upstream's dev.Caddyfile, which enables 'anonymous' — that lets a
# client subscribe with no token at all. Every topic the wiki publishes is private and
# Space-scoped, so anonymous subscription would hand the whole change stream of every
# Space to anyone who can reach the port.
#
# cors_origins and publish_origins are left unset: the hub is reached only through the
# wiki's own origin via a reverse proxy, and the wiki publishes from loopback with a
# bearer token, which is not an origin-checked request.
{
	auto_https off
	persist_config off
	admin off
}

# Browsers reach the hub through the wiki's reverse proxy, so the port itself is never
# exposed. Two details here, both found by testing rather than by reading:
#
#   'http://' is required, not decorative. Caddy treats a bare host:port as an HTTPS
#   site, and 'auto_https off' only stops it managing certificates — without the scheme
#   the listener still expects TLS and every plain request comes back with
#   "Client sent an HTTP request to an HTTPS server".
#
#   'bind' is what restricts the interface. A site address of http://127.0.0.1:$PORT
#   looks like it would, but that host is matched against the Host *header* while the
#   listener still answers on every interface. It would also 404 whatever the reverse
#   proxy forwards with the original Host, which is what 'proxy_set_header Host \$host'
#   sends. So: no host in the address, and the interface pinned with 'bind'.
http://:$PORT {
	bind 127.0.0.1

	log {
		format filter {
			fields {
				# A non-browser subscriber may pass its ticket as a query parameter.
				# Without this the credential lands in the journal.
				request>uri query {
					replace authorization REDACTED
				}
			}
		}
		output stdout
	}

	mercure {
		publisher_jwt  {env.MERCURE_PUBLISHER_JWT_KEY}
		subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY}
	}

	respond /healthz 200
	respond "Not Found" 404
}
EOF
chmod 0640 "$CADDYFILE"
wrote "$CADDYFILE" "hub configuration"

if [ -n "${STAGING+x}" ] && [ "$STAGING" -eq 0 ]; then
    chown -R "root:$SVC_USER" "$ETC_DIR"
    info "ownership root:$SVC_USER on $ETC_DIR (readable by the service, not by others)"
fi

step "Validating the generated configuration"
if MERCURE_PUBLISHER_JWT_KEY="$KEY" MERCURE_SUBSCRIBER_JWT_KEY="$KEY" \
   "$BIN_DIR/mercure" validate --config "$CADDYFILE" >"$TMP/validate.log" 2>&1; then
    ok "$CADDYFILE is valid"
else
    sed 's/^/      /' "$TMP/validate.log" >&2
    die "the generated Caddyfile did not validate (see above)"
fi

# ── 7. systemd unit ──────────────────────────────────────────────────────────
step "systemd service"

install -d -m 0755 "$(dirname "$UNIT")"
cat > "$UNIT" <<EOF
# Generated by install-mercure.sh for Astucia Wiki.
[Unit]
Description=Mercure hub (Astucia Wiki realtime)
Documentation=https://mercure.rocks
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$SVC_USER
Group=$SVC_USER
EnvironmentFile=${ETC_DIR#$ROOT}/mercure.env
ExecStart=${BIN_DIR#$ROOT}/mercure run --config ${CADDYFILE#$ROOT}
Restart=on-failure
RestartSec=5s

# Caddy insists on a writable data directory. StateDirectory creates and owns
# /var/lib/mercure, and pointing XDG_DATA_HOME there keeps it inside the one path
# ProtectSystem=strict leaves writable.
StateDirectory=mercure
Environment=XDG_DATA_HOME=/var/lib/mercure XDG_CONFIG_HOME=/var/lib/mercure

# The hub relays notifications and holds a signing key. It needs a socket and nothing
# else, so it gets nothing else.
NoNewPrivileges=true
PrivateTmp=true
PrivateDevices=true
ProtectSystem=strict
ProtectHome=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictAddressFamilies=AF_INET AF_INET6
RestrictNamespaces=true
RestrictSUIDSGID=true
LockPersonality=true
SystemCallArchitectures=native

[Install]
WantedBy=multi-user.target
EOF
chmod 0644 "$UNIT"
wrote "$UNIT" "systemd unit"

if [ "$STAGING" -eq 1 ]; then
    step "Staging build complete"
    dim "would run: systemctl daemon-reload && systemctl enable --now mercure"
    dim "nothing was started"
else
    info "systemctl daemon-reload"
    systemctl daemon-reload
    info "systemctl enable --now mercure"
    systemctl enable --now mercure >/dev/null 2>&1 || systemctl enable mercure >/dev/null 2>&1
    systemctl restart mercure
    ok "mercure.service enabled and started"

    # ── 8. does it actually answer? ──────────────────────────────────────────
    step "Checking the hub responds"
    up=0
    for _ in $(seq 1 20); do
        # 401 is the *right* answer: the hub is up and refusing an unauthenticated
        # subscribe, which is what 'anonymous' being off means.
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 \
               "http://127.0.0.1:$PORT/.well-known/mercure?topic=test" || true)
        if [ "$code" = "401" ]; then ok "hub answers on 127.0.0.1:$PORT (HTTP 401 — up, and refusing anonymous)"; up=1; break; fi
        if [ "$code" = "200" ]; then warn "hub answers 200 to an unauthenticated subscribe — check $CADDYFILE"; up=1; break; fi
        sleep 0.5
    done
    if [ "$up" -eq 0 ]; then
        printf '\n'
        systemctl --no-pager --lines=20 status mercure || true
        die "the hub did not answer on port $PORT — see the status above, or: journalctl -u mercure -n 50"
    fi
    [ "$RESTART_AFTER" -eq 1 ] && info "(upgraded in place from: ${CURRENT:-unknown})"
fi

# ── 9. what the operator still has to do ─────────────────────────────────────
cat > "$SNIPPET" <<EOF
<?php
// Generated by install-mercure.sh — add these to the wiki's config.php.
// MERCURE_JWT_KEY must match ${KEY_FILE#$ROOT}; the hub and the wiki sign with one key.
define('ENABLE_REALTIME', true);
define('MERCURE_JWT_KEY', '$KEY');
define('MERCURE_INTERNAL_URL', 'http://127.0.0.1:$PORT');
define('MERCURE_PUBLIC_URL', '/.well-known/mercure');
define('REALTIME_TICKET_TTL', 3600);
EOF
chmod 0600 "$SNIPPET"

step "Installed — two things left to do"
wrote "$SNIPPET" "the config.php lines, including the key"
cat <<EOF

    ${B}1. Add the constants to the wiki's config.php${N}

       They are written out for you, key included. The key is not printed here on
       purpose — terminal scrollback gets copied into tickets and chat logs.

           sudo cat ${SNIPPET#$ROOT}

    ${B}2. Reverse-proxy /.well-known/mercure to the hub${N}

       The browser must reach the hub on the wiki's own origin, or the ticket cookie
       will not be sent. For nginx, inside the wiki's server block:

           location ^~ /.well-known/mercure {
               proxy_pass          http://127.0.0.1:$PORT;
               proxy_http_version  1.1;
               proxy_set_header    Connection '';
               proxy_set_header    Host \$host;
               proxy_set_header    X-Forwarded-For \$proxy_add_x_forwarded_for;
               proxy_set_header    X-Forwarded-Proto \$scheme;
               proxy_buffering     off;        # or events queue in a buffer
               proxy_cache         off;
               proxy_read_timeout  24h;        # the stream is meant to stay open
               chunked_transfer_encoding off;
           }

       ${D}Put it BEFORE any 'location ~ /\\.' deny rule — that pattern matches
       /.well-known/mercure, and nginx prefers a regex match over a prefix one.
       The '^~' modifier is what stops it.${N}

       ${D}Realtime wants HTTP/2, so terminate TLS at the proxy. An SSE stream holds
       one of the browser's ~6 connections per origin for as long as it lives.${N}

    ${B}Handy afterwards${N}

           systemctl status mercure
           journalctl -u mercure -f
           sudo $0 <newer-version>   ${D}# upgrade in place; the key is kept${N}

EOF
