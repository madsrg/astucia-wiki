#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
# =============================================================================
# Install, configure and run the Mercure hub as a systemd service.
#
#   sudo ./tools/install-mercure.sh 1.1.5
#
# The wiki's realtime push needs a Mercure hub. The Docker image bundles one; this is
# the equivalent for a bare-metal install. It downloads the release, verifies its
# checksum, installs the binary, creates a locked-down service account, generates the
# shared key, writes the hub config and a hardened systemd unit, starts it and checks
# that it answers.
#
# ONE HUB SERVES ONE WIKI. Every wiki publishes the same topic names (wiki/Main/…,
# wiki/user/7/…), so two wikis on one hub receive each other's change events, and with
# one key either wiki can sign tickets the other's hub accepts. For several wikis on one
# machine, give each its own *instance*:
#
#   sudo MERCURE_INSTANCE=team ./tools/install-mercure.sh 1.1.5
#
# Without MERCURE_INSTANCE the script works on the default instance — the single-wiki
# layout this script has always written (/etc/mercure, mercure.service).
#
# Re-running upgrades in place, and upgrades EVERY instance on the machine: they share
# one binary, so replacing it is an upgrade for all of them whether asked for or not. Each
# instance's config is regenerated from the settings it was installed with and its
# service restarted. The script prints the plan and waits for a key press before doing
# anything. It never overwrites a key — regenerating one would silently break the wiki's
# config, so an existing key is kept.
#
# Everything it does is printed, including every path it writes.
#
# Optional environment overrides (the version stays the only argument):
#   MERCURE_INSTANCE=        name of the instance to create or upgrade (a-z, 0-9, '-').
#                            Unset means the default instance — except on a machine whose
#                            only instances are named ones, where it means "upgrade them"
#                            and creates nothing. MERCURE_INSTANCE=default always means the
#                            default instance, creating it if need be
#   MERCURE_PORT=            port the instance listens on, loopback only. Default 3000
#                            for the default instance, the first free port from 3001 for
#                            a named one; an existing instance keeps its port
#   MERCURE_USER=            service account to create and run as. Default 'mercure', or
#                            'mercure-<instance>', so one hub cannot read another's key
#   MERCURE_ISSUER, MERCURE_RESOURCE_ID, MERCURE_COOKIE_NAME
#                            the identities the wiki and the hub must agree on. Defaults
#                            are distinct per instance; an existing instance keeps its own
#   MERCURE_ASSUME_YES=1     skip the key press (required when not run from a terminal)
#   INSTALL_ROOT=            prefix every path; for staging or testing a build without
#                            touching the real system (skips useradd and systemctl)
# =============================================================================

set -euo pipefail

VERSION="${1:-}"
INSTANCE="${MERCURE_INSTANCE:-}"
ROOT="${INSTALL_ROOT:-}"
ASSUME_YES="${MERCURE_ASSUME_YES:-0}"

BIN_DIR="$ROOT/usr/local/bin"
LIC_DIR="$ROOT/usr/share/licenses/mercure"
# The machine-wide version stamp, beside the binary it describes rather than inside an
# instance's config directory: those are 0750 root:<service account>, which the web
# server cannot read, and every instance runs this one binary anyway. It is the same path
# the Docker image writes, so wiki_mercure_version() already looks there.
STAMP_DIR="$ROOT/usr/local/share/astucia"

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
Usage: sudo [MERCURE_INSTANCE=<name>] $0 <version>

  <version>   Mercure release to install, without the leading 'v'. For example:
                sudo $0 1.1.5
              Releases: https://github.com/dunglas/mercure/releases

              The wiki mints Mercure 1.0 access tokens (OAuth 2.0), which a 0.x hub
              rejects, so 1.0.0 is the minimum. 1.0.2 fixed ten vulnerabilities an
              audit found in 1.0.0, 1.0.3 adds Caddy's security hardening, 1.1.0 closes
              the gaps a second audit found, 1.1.1 updates the hub's HTTP/2 stack,
              1.1.2 fixes how a hub behind a proxy names itself, 1.1.4 closes two
              authorization gaps (1.1.3 was never released), and 1.1.5 is built with
              a Go release that fixes certificate validation — so 1.1.5 is what to
              install.

  One hub serves one wiki. Running several wikis on this machine? Give each its own
  instance with MERCURE_INSTANCE=<name>. Without it, the default instance is used.

Re-run with a newer version to upgrade. Every instance on the machine is upgraded and
restarted, since they share one binary. Keys are never regenerated.
EOF
    exit 1
}

[ -n "$VERSION" ] || usage
case "$VERSION" in
    -h|--help) usage ;;
    v*) VERSION="${VERSION#v}" ;;   # tolerate 'v1.0.2'
esac
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]] || die "'$VERSION' is not a version like 1.1.5"
# Refused rather than installed and left to fail at the first publish: the wiki signs
# OAuth 2.0 access tokens (typ at+jwt, iss, aud, authorization_details), and a 0.x hub
# answers 401 to every one of them while looking perfectly healthy from the outside.
case "$VERSION" in
    0.*) die "Mercure $VERSION is too old for this wiki — it needs 1.0.0 or newer, whose
      access-token format the wiki signs. Install 1.1.5 or later." ;;
    1.0.1) die "Mercure 1.0.1 was tagged but never released — its build failed on 32-bit
      platforms, so there are no binaries to download. Install 1.1.5." ;;
    1.1.3) die "Mercure 1.1.3 was tagged but never released — its fixes shipped in 1.1.4,
      so there are no binaries to download. Install 1.1.5." ;;
esac

# An instance name becomes part of a directory, a systemd unit, a user name and a cookie
# name, so it is held to what all four accept. 'default' is not a name but the explicit
# spelling of the default instance, so it can be created on a machine that has only named
# ones (where leaving the variable unset means "upgrade what is there").
EXPLICIT_DEFAULT=0
if [ "$INSTANCE" = "default" ]; then INSTANCE=""; EXPLICIT_DEFAULT=1; fi
if [ -n "$INSTANCE" ]; then
    [[ "$INSTANCE" =~ ^[a-z0-9][a-z0-9-]{0,23}$ ]] \
        || die "MERCURE_INSTANCE='$INSTANCE' — use 1–24 characters: a-z, 0-9 and '-', not starting with '-'"
fi

# The floor is the newest release that fixes something reachable in *this* setup.
# 1.0.2: an audit found ten vulnerabilities, four of them high, including one hub
# receiving another's private updates. 1.0.3: Caddy 2.11.7's hardening — idle read/write
# timeouts against slowloris and a 16 KiB request-header cap. 1.1.0: a subscribe request
# could make the hub allocate about 1 GiB compiling its topic patterns, and every signed-in
# user holds a ticket that gets that far. 1.1.1 is not the floor: its fixes are in the
# hub's HTTP/2 server, which only loopback can reach — the reverse proxy speaks HTTP/1.1.
# Older 1.0.x still speaks the protocol this wiki signs for, so this is a warning rather
# than a refusal — the line being: refuse what cannot work, warn about what works but
# should not be run.
MIN_SECURE=1.1.0
if [ "$(printf '%s\n%s\n' "$MIN_SECURE" "$VERSION" | sort -V | head -1)" != "$MIN_SECURE" ]; then
    warn "Mercure $VERSION is older than $MIN_SECURE, the current security release."
    dim  "https://github.com/dunglas/mercure/releases/tag/v$MIN_SECURE"
fi

# ── instances ────────────────────────────────────────────────────────────────
# An instance is a config directory, a systemd unit and a service account; the empty
# name is the default instance and keeps the paths this script has always used, so an
# existing single-wiki install is simply the default instance with nothing to migrate.
inst_label() { [ -n "$1" ] && printf '%s' "$1" || printf 'default'; }
inst_etc()   { [ -n "$1" ] && printf '%s/etc/mercure-%s' "$ROOT" "$1" || printf '%s/etc/mercure' "$ROOT"; }
inst_unit()  { [ -n "$1" ] && printf 'mercure-%s' "$1" || printf 'mercure'; }

# Every instance this script installed: a config directory holding a Caddyfile it wrote.
# The default instance is listed first.
discover_instances() {
    local d name
    for d in "$ROOT/etc/mercure" "$ROOT"/etc/mercure-*; do
        [ -f "$d/Caddyfile" ] || continue
        grep -q 'install-mercure.sh' "$d/Caddyfile" 2>/dev/null || continue
        name=${d#"$ROOT/etc/mercure"}; name=${name#-}
        printf '%s\n' "$name"
    done
}

# Loads one instance's settings into I_PORT, I_USER, I_ISSUER, I_RESOURCE_ID, I_COOKIE.
# instance.conf is written by this script from now on. An instance installed before it
# existed has none, so its settings are read back out of the Caddyfile and unit it was
# installed with — the alternative, falling back to defaults, would quietly move a hub
# that had been given MERCURE_PORT=3005 back to 3000 on its next upgrade.
load_instance() {
    local name=$1 etc conf caddy unit
    etc=$(inst_etc "$name"); conf="$etc/instance.conf"; caddy="$etc/Caddyfile"
    unit="$ROOT/etc/systemd/system/$(inst_unit "$name").service"
    I_PORT=; I_USER=; I_ISSUER=; I_RESOURCE_ID=; I_COOKIE=
    if [ -f "$conf" ]; then
        I_PORT=$(sed -n 's/^PORT=//p' "$conf")
        I_USER=$(sed -n 's/^SVC_USER=//p' "$conf")
        I_ISSUER=$(sed -n 's/^ISSUER=//p' "$conf")
        I_RESOURCE_ID=$(sed -n 's/^RESOURCE_ID=//p' "$conf")
        I_COOKIE=$(sed -n 's/^COOKIE_NAME=//p' "$conf")
    elif [ -f "$caddy" ]; then
        I_PORT=$(sed -n 's/^http:\/\/:\([0-9][0-9]*\) {$/\1/p' "$caddy" | head -1)
        I_ISSUER=$(sed -n 's/^[[:space:]]*issuer \([^ ]*\) {$/\1/p' "$caddy" | head -1)
        I_RESOURCE_ID=$(sed -n 's/^[[:space:]]*resource_identifier \([^ ]*\)$/\1/p' "$caddy" | head -1)
        I_COOKIE=$(sed -n 's/^[[:space:]]*cookie_name \([^ ]*\)$/\1/p' "$caddy" | head -1)
        [ -f "$unit" ] && I_USER=$(sed -n 's/^User=//p' "$unit" | head -1)
    fi
    # Anything still missing gets the default the instance would have been installed with.
    if [ -n "$name" ]; then
        : "${I_USER:=mercure-$name}"
        : "${I_ISSUER:=https://astucia.invalid/$name/wiki}"
        : "${I_RESOURCE_ID:=https://$name.astucia.invalid/.well-known/mercure}"
        : "${I_COOKIE:=mercure_access_token_$name}"
    else
        : "${I_PORT:=3000}"
        : "${I_USER:=mercure}"
        : "${I_ISSUER:=https://astucia.invalid/wiki}"
        : "${I_RESOURCE_ID:=https://astucia.invalid/.well-known/mercure}"
        : "${I_COOKIE:=mercure_access_token}"
    fi
}

port_listening() {
    command -v ss >/dev/null 2>&1 || return 1
    ss -ltnH "sport = :$1" 2>/dev/null | grep -q .
}

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
    info "existing binary: ${CURRENT:-unknown}"
fi

# ── 2. plan ──────────────────────────────────────────────────────────────────
# Everything is decided before anything is touched, so the operator sees the whole of it
# — in particular which *other* instances an upgrade is about to restart.
mapfile -t EXISTING < <(discover_instances)

TARGET_NEW=1
for n in "${EXISTING[@]}"; do [ "$n" = "$INSTANCE" ] && TARGET_NEW=0; done

# MERCURE_INSTANCE unset on a machine whose instances are all named: that is someone
# upgrading who did not think to name one — creating a default instance on port 3000
# would be a surprise nobody asked for. Upgrade everything and create nothing.
UPGRADE_ONLY=0
if [ -z "$INSTANCE" ] && [ "$EXPLICIT_DEFAULT" -eq 0 ] && [ "$TARGET_NEW" -eq 1 ] \
   && [ "${#EXISTING[@]}" -gt 0 ]; then
    UPGRADE_ONLY=1
    for v in MERCURE_PORT MERCURE_USER MERCURE_ISSUER MERCURE_RESOURCE_ID MERCURE_COOKIE_NAME; do
        [ -z "${!v:-}" ] || die "$v is set but no instance is named — say which one with MERCURE_INSTANCE"
    done
fi

ALL=("${EXISTING[@]}")
[ "$TARGET_NEW" -eq 1 ] && [ "$UPGRADE_ONLY" -eq 0 ] && ALL+=("$INSTANCE")

# Settings per instance, in parallel arrays indexed like ALL. Every instance keeps what
# it has; the overrides apply to the target instance only.
declare -A P_PORT P_USER P_ISSUER P_RESOURCE_ID P_COOKIE
for n in "${ALL[@]}"; do
    load_instance "$n"
    P_PORT[x$n]=$I_PORT; P_USER[x$n]=$I_USER; P_ISSUER[x$n]=$I_ISSUER
    P_RESOURCE_ID[x$n]=$I_RESOURCE_ID; P_COOKIE[x$n]=$I_COOKIE
done
T=x$INSTANCE
TARGET_CHANGED=0
if [ "$UPGRADE_ONLY" -eq 0 ]; then
    BEFORE="${P_PORT[$T]:-}|${P_USER[$T]}|${P_ISSUER[$T]}|${P_RESOURCE_ID[$T]}|${P_COOKIE[$T]}"
    [ -n "${MERCURE_PORT:-}" ]        && P_PORT[$T]=$MERCURE_PORT
    [ -n "${MERCURE_USER:-}" ]        && P_USER[$T]=$MERCURE_USER
    [ -n "${MERCURE_ISSUER:-}" ]      && P_ISSUER[$T]=$MERCURE_ISSUER
    [ -n "${MERCURE_RESOURCE_ID:-}" ] && P_RESOURCE_ID[$T]=$MERCURE_RESOURCE_ID
    [ -n "${MERCURE_COOKIE_NAME:-}" ] && P_COOKIE[$T]=$MERCURE_COOKIE_NAME

    # A new named instance takes the first port from 3001 that no instance claims and
    # nothing is listening on. Checked against the other instances' configs as well as the
    # socket table, because a stopped instance listens on nothing and still owns its port.
    port_claimed() {
        local p=$1 n
        for n in "${ALL[@]}"; do [ "$n" != "$INSTANCE" ] && [ "${P_PORT[x$n]}" = "$p" ] && return 0; done
        return 1
    }
    if [ -z "${P_PORT[$T]}" ]; then
        p=3001
        while port_claimed "$p" || port_listening "$p"; do p=$((p + 1)); done
        P_PORT[$T]=$p
    fi
    [[ "${P_PORT[$T]}" =~ ^[0-9]+$ ]] || die "MERCURE_PORT='${P_PORT[$T]}' is not a port number"
    port_claimed "${P_PORT[$T]}" \
        && die "port ${P_PORT[$T]} already belongs to another instance on this machine"
    if [ "$TARGET_NEW" -eq 1 ] && port_listening "${P_PORT[$T]}"; then
        die "something is already listening on port ${P_PORT[$T]} — choose another with MERCURE_PORT"
    fi

    # An existing instance given a new port or identity: its wiki's config.php must follow.
    AFTER="${P_PORT[$T]}|${P_USER[$T]}|${P_ISSUER[$T]}|${P_RESOURCE_ID[$T]}|${P_COOKIE[$T]}"
    [ "$TARGET_NEW" -eq 0 ] && [ "$BEFORE" != "$AFTER" ] && TARGET_CHANGED=1
fi

step "What this will do"
info "Install Mercure $VERSION as $BIN_DIR/mercure${CURRENT:+ (replacing: $CURRENT)}"
dim  "One binary serves every instance on this machine."
for n in "${ALL[@]}"; do
    label=$(inst_label "$n"); unit=$(inst_unit "$n")
    if [ "$UPGRADE_ONLY" -eq 1 ] || [ "$n" != "$INSTANCE" ]; then
        info "Upgrade instance '$label' — $unit.service on 127.0.0.1:${P_PORT[x$n]}"
    elif [ "$TARGET_NEW" -eq 1 ]; then
        info "${B}Create${N}  instance '$label' — $unit.service on 127.0.0.1:${P_PORT[x$n]}, $(inst_etc "$n")"
    else
        info "${B}Upgrade${N} instance '$label' — $unit.service on 127.0.0.1:${P_PORT[x$n]}"
        [ "$TARGET_CHANGED" -eq 0 ] \
            || warn "its settings change — that wiki's config.php must be updated to match"
    fi
done
[ "$UPGRADE_ONLY" -eq 1 ] \
    && dim "No default instance exists here, so none is created (MERCURE_INSTANCE=default would)."

cat <<EOF

    ${B}One hub serves one wiki.${N} Every wiki publishes the same topic names, so two
    wikis sharing a hub receive each other's change events — page and Space names
    included — and either can sign tickets the other accepts. Running another wiki on
    this machine? Abort, and give it its own instance:

        sudo MERCURE_INSTANCE=<name> $0 $VERSION

    Without MERCURE_INSTANCE this script works on the ${B}default${N} instance.
EOF

if [ "$ASSUME_YES" != "1" ]; then
    # Read from the terminal, not stdin: stdin may be a pipe, and a script run unattended
    # must not appear to hang waiting for a key nobody can press.
    if ! { : </dev/tty; } 2>/dev/null; then
        die "no terminal to confirm on — set MERCURE_ASSUME_YES=1 to run unattended"
    fi
    printf '\n    Press any key to continue, or Ctrl+C to abort. '
    IFS= read -r -s -n 1 _ </dev/tty || die "aborted"
    printf '\n'
fi

# ── 3. download and verify ───────────────────────────────────────────────────
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

# ── 4. stop every running instance before replacing the binary ──────────────
# Every instance runs the binary about to be replaced, so every running one is stopped,
# and exactly those are started again afterwards. An instance the operator had stopped
# stays stopped — it is upgraded, not resurrected.
WAS_ACTIVE=()
if [ "$STAGING" -eq 0 ]; then
    for n in "${EXISTING[@]}"; do
        unit=$(inst_unit "$n")
        if systemctl is-active --quiet "$unit" 2>/dev/null; then
            [ "${#WAS_ACTIVE[@]}" -eq 0 ] && step "Stopping running hubs before replacing the binary"
            systemctl stop "$unit"
            WAS_ACTIVE+=("$n")
            ok "$unit.service stopped"
        fi
    done
fi

# ── 5. install files ─────────────────────────────────────────────────────────
step "Installing files"

install -d -m 0755 "$BIN_DIR"
install -m 0755 "$TMP/x/mercure" "$BIN_DIR/mercure"
wrote "$BIN_DIR/mercure" "the hub binary, shared by every instance"

# Stamped where the wiki can read it: the hub advertises no version over HTTP, so
# Admin → Wiki Info would otherwise have to report the pin the *image* intends rather
# than the hub this machine actually runs — which are routinely different.
HUB_VERSION=$("$BIN_DIR/mercure" --version 2>/dev/null | head -1 || true)
[ -n "$HUB_VERSION" ] || HUB_VERSION=$VERSION
install -d -m 0755 "$STAMP_DIR"
printf '%s\n' "$HUB_VERSION" > "$STAMP_DIR/mercure.version"
chmod 0644 "$STAMP_DIR/mercure.version"
wrote "$STAMP_DIR/mercure.version" "version stamp, read by Admin → Wiki Info"

# The hub is AGPL-3.0 and this is a redistribution of an unmodified upstream build, so
# its licence and copyright travel with it.
install -d -m 0755 "$LIC_DIR"
for f in LICENSE COPYRIGHT; do
    [ -f "$TMP/x/$f" ] && install -m 0644 "$TMP/x/$f" "$LIC_DIR/$f" && wrote "$LIC_DIR/$f"
done

# ── 6. configure each instance ───────────────────────────────────────────────
# Regenerated from the settings loaded above, so an upgrade also carries any change to
# the Caddyfile this script writes (the write_idle workaround arrived exactly this way).
# A hand edit to a generated Caddyfile is overwritten, as it always was on a re-run.
configure_instance() {
    local name=$1
    local label etc unit_name
    label=$(inst_label "$name"); etc=$(inst_etc "$name"); unit_name=$(inst_unit "$name")
    local PORT=${P_PORT[x$name]} SVC_USER=${P_USER[x$name]}
    local ISSUER=${P_ISSUER[x$name]} RESOURCE_ID=${P_RESOURCE_ID[x$name]} COOKIE_NAME=${P_COOKIE[x$name]}
    local KEY_FILE="$etc/mercure.key" ENV_FILE="$etc/mercure.env" CADDYFILE="$etc/Caddyfile"
    local CONF="$etc/instance.conf" SNIPPET="$etc/wiki-config-snippet.php"
    local UNIT="$ROOT/etc/systemd/system/$unit_name.service"
    local KEY

    step "Instance '$label'"

    # Service account. One per instance, so a compromised hub process cannot read
    # another instance's key: the config directory is 0750 root:<account>.
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

    install -d -m 0750 "$etc"
    info "config directory $etc"
    # Where earlier versions of this script stamped the version: inside a directory the web
    # server cannot traverse, so the wiki never read it. Removed rather than left to go
    # stale beside the stamp that replaced it.
    rm -f "$etc/version"

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

    # What a re-run reads back, so an upgrade keeps this instance exactly as it is.
    cat > "$CONF" <<EOF
# Generated by install-mercure.sh — this instance's settings, read back on every re-run
# so an upgrade keeps them. Change one by re-running with the matching MERCURE_* variable.
PORT=$PORT
SVC_USER=$SVC_USER
ISSUER=$ISSUER
RESOURCE_ID=$RESOURCE_ID
COOKIE_NAME=$COOKIE_NAME
EOF
    chmod 0640 "$CONF"
    wrote "$CONF" "instance settings"

    cat > "$CADDYFILE" <<EOF
# Generated by install-mercure.sh for Astucia Wiki — instance '$label'.
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
	# Mercure 1.0.4's advice for any Caddyfile on Caddy 2.11.7: the new write_idle timeout
	# (1 minute by default) overrides the hub's dispatch_timeout, so one subscriber that
	# stops reading can hold a dispatch for a minute instead of a few seconds. It bounds a
	# write that is *stuck*, not quiet time between writes — an idle stream with a pause of
	# 70 s still received its next event, verified against 1.0.4 to 1.1.4 — so it costs
	# nothing. Drop it once the hub is built on a Caddy release that includes
	# caddyserver/caddy#8145 (merged, but 1.1.5 is still on 2.11.7).
	servers {
		timeouts {
			write_idle 5s
		}
	}
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

	# No query-parameter redaction filter here any more: Mercure 1.0 removed the
	# 'authorization' query parameter outright (RFC 9700 forbids access tokens in URLs),
	# so there is no credential in a URL left to redact.
	log {
		output stdout
	}

	mercure {
		# The wiki is its own authorization server: it signs access tokens with the shared
		# key below and this hub verifies them with the same one. 'issuer' binds the 'iss'
		# those tokens carry to the key allowed to verify it — it replaced the top-level
		# publisher_jwt/subscriber_jwt directives, which since 1.0 work only in
		# compatibility mode, and that mode switches off the exp, audience, at+jwt and
		# issuer checks wholesale.
		#
		# These three values are written as literals on purpose. Caddy expands {env.…} for
		# 'jwt' but NOT for 'issuer', 'resource_identifier' or 'cookie_name', and it does
		# it silently: the hub validates, starts, and trusts an issuer literally named
		# "{env.MERCURE_ISSUER}" — so a correctly configured wiki gets 401 on every publish
		# while every health check passes. Each must equal the matching constant in the
		# wiki's config.php (see ${SNIPPET#$ROOT}).
		issuer $ISSUER {
			publisher {
				jwt {env.MERCURE_PUBLISHER_JWT_KEY}
			}
			subscriber {
				jwt {env.MERCURE_SUBSCRIBER_JWT_KEY}
			}
		}

		# The audience every token must carry. Pinned rather than derived per request:
		# PHP reaches this hub on loopback while the browser reaches it through the reverse
		# proxy on the wiki's origin — two URLs, one hub, and without pinning two different
		# audiences that one token cannot satisfy. The /.well-known/mercure suffix is
		# load-bearing: the hub uses it as the base for a ticket's URL Patterns.
		resource_identifier $RESOURCE_ID

		# 1.0 defaults to '__Secure-mercure_access_token', and a browser refuses a
		# __Secure- cookie over plain HTTP — which would kill realtime on a wiki served
		# over HTTP on a LAN hostname. The wiki marks the cookie Secure per request instead.
		cookie_name $COOKIE_NAME
	}

	respond /healthz 200
	respond "Not Found" 404
}
EOF
    chmod 0640 "$CADDYFILE"
    wrote "$CADDYFILE" "hub configuration"

    if [ "$STAGING" -eq 0 ]; then
        chown -R "root:$SVC_USER" "$etc"
        info "ownership root:$SVC_USER on $etc (readable by the service, not by others)"
    fi

    if MERCURE_PUBLISHER_JWT_KEY="$KEY" MERCURE_SUBSCRIBER_JWT_KEY="$KEY" \
       "$BIN_DIR/mercure" validate --config "$CADDYFILE" >"$TMP/validate.log" 2>&1; then
        ok "$CADDYFILE is valid"
    else
        sed 's/^/      /' "$TMP/validate.log" >&2
        die "the generated Caddyfile did not validate (see above)"
    fi

    install -d -m 0755 "$(dirname "$UNIT")"
    cat > "$UNIT" <<EOF
# Generated by install-mercure.sh for Astucia Wiki — instance '$label'.
[Unit]
Description=Mercure hub (Astucia Wiki realtime, instance '$label')
Documentation=https://mercure.rocks
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$SVC_USER
Group=$SVC_USER
EnvironmentFile=${ENV_FILE#$ROOT}
ExecStart=${BIN_DIR#$ROOT}/mercure run --config ${CADDYFILE#$ROOT}
Restart=on-failure
RestartSec=5s

# Caddy insists on a writable data directory. StateDirectory creates and owns
# /var/lib/$unit_name, and pointing XDG_DATA_HOME there keeps it inside the one path
# ProtectSystem=strict leaves writable.
StateDirectory=$unit_name
Environment=XDG_DATA_HOME=/var/lib/$unit_name XDG_CONFIG_HOME=/var/lib/$unit_name

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

    cat > "$SNIPPET" <<EOF
<?php
// Generated by install-mercure.sh (instance '$label') — add these to the wiki's config.php.
// MERCURE_JWT_KEY must match ${KEY_FILE#$ROOT}; the hub and the wiki sign with one key.
define('ENABLE_REALTIME', true);
define('MERCURE_JWT_KEY', '$KEY');
define('MERCURE_INTERNAL_URL', 'http://127.0.0.1:$PORT');
define('MERCURE_PUBLIC_URL', '/.well-known/mercure');
define('REALTIME_TICKET_TTL', 3600);
// These three must match the hub's Caddyfile exactly — the wiki signs OAuth 2.0 access
// tokens and the hub verifies the issuer, the audience and the cookie name.
define('MERCURE_ISSUER', '$ISSUER');
define('MERCURE_RESOURCE_ID', '$RESOURCE_ID');
define('MERCURE_COOKIE_NAME', '$COOKIE_NAME');
EOF
    chmod 0600 "$SNIPPET"
    wrote "$SNIPPET" "the config.php lines, including the key"
}

for n in "${ALL[@]}"; do configure_instance "$n"; done

# ── 7. start, and check each hub answers ─────────────────────────────────────
check_hub() {
    local name=$1 port=${P_PORT[x$1]} unit code
    unit=$(inst_unit "$name")
    for _ in $(seq 1 20); do
        # 401 is the *right* answer: the hub is up and refusing an unauthenticated
        # subscribe, which is what 'anonymous' being off means. 'match=' is 1.0's spelling
        # of what used to be 'topic=' (authorization is checked first either way, so this
        # would answer 401 regardless — it is written correctly so it keeps meaning what it
        # says if that order ever changes).
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 \
               "http://127.0.0.1:$port/.well-known/mercure?match=test" || true)
        if [ "$code" = "401" ]; then ok "'$(inst_label "$name")' answers on 127.0.0.1:$port (HTTP 401 — up, and refusing anonymous)"; return 0; fi
        if [ "$code" = "200" ]; then warn "'$(inst_label "$name")' answers 200 to an unauthenticated subscribe — check $(inst_etc "$name")/Caddyfile"; return 0; fi
        sleep 0.5
    done
    printf '\n'
    systemctl --no-pager --lines=20 status "$unit" || true
    die "instance '$(inst_label "$name")' did not answer on port $port — see the status above, or: journalctl -u $unit -n 50"
}

if [ "$STAGING" -eq 1 ]; then
    step "Staging build complete"
    for n in "${ALL[@]}"; do
        if [ "$UPGRADE_ONLY" -eq 0 ] && [ "$n" = "$INSTANCE" ]; then
            dim "would run: systemctl enable $(inst_unit "$n") && systemctl restart $(inst_unit "$n")"
        else
            dim "would restart $(inst_unit "$n") if it was running"
        fi
    done
    dim "nothing was started"
else
    step "Starting"
    info "systemctl daemon-reload"
    systemctl daemon-reload
    # The target is enabled and started whatever state it was in — that is what was asked
    # for. The others go back to exactly the state they were found in.
    START=()
    [ "$UPGRADE_ONLY" -eq 1 ] || START=("$INSTANCE")
    for n in "${WAS_ACTIVE[@]}"; do
        [ "$UPGRADE_ONLY" -eq 0 ] && [ "$n" = "$INSTANCE" ] && continue
        START+=("$n")
    done
    for n in "${START[@]}"; do
        unit=$(inst_unit "$n")
        if [ "$UPGRADE_ONLY" -eq 0 ] && [ "$n" = "$INSTANCE" ]; then
            systemctl enable "$unit" >/dev/null 2>&1 || true
        fi
        systemctl restart "$unit"
        ok "$unit.service started"
    done
    for n in "${ALL[@]}"; do
        skip=1; for s in "${START[@]}"; do [ "$s" = "$n" ] && skip=0; done
        [ "$skip" -eq 1 ] && dim "$(inst_unit "$n").service was stopped before this run and is left stopped"
    done
    step "Checking the hubs respond"
    for n in "${START[@]}"; do check_hub "$n"; done
    [ -n "$CURRENT" ] && info "(upgraded from: $CURRENT)"
fi

# ── 8. what the operator still has to do ─────────────────────────────────────
if [ "$UPGRADE_ONLY" -eq 1 ] || { [ "$TARGET_NEW" -eq 0 ] && [ "$TARGET_CHANGED" -eq 0 ]; }; then
    step "Upgraded — nothing else to do"
    info "Every wiki's config.php is unchanged: keys, ports and identities were kept."
    cat <<EOF

    ${B}Handy afterwards${N}

           systemctl list-units 'mercure*'
           journalctl -u <unit> -f
           sudo $0 <newer-version>   ${D}# upgrades every instance; keys are kept${N}

EOF
    exit 0
fi

T_LABEL=$(inst_label "$INSTANCE"); T_UNIT=$(inst_unit "$INSTANCE"); T_ETC=$(inst_etc "$INSTANCE")
T_PORT=${P_PORT[$T]}

if [ "$TARGET_CHANGED" -eq 1 ]; then
    step "Instance '$T_LABEL' changed — update its wiki to match"
else
    step "Installed instance '$T_LABEL' — two things left to do"
fi
cat <<EOF

    ${B}1. Add the constants to the wiki's config.php${N}

       They are written out for you, key included. The key is not printed here on
       purpose — terminal scrollback gets copied into tickets and chat logs.

           sudo cat ${T_ETC#$ROOT}/wiki-config-snippet.php$( [ -n "$INSTANCE" ] && printf '\n\n       %sA named instance has its own issuer, audience and cookie name — copy all\n       eight lines, not only the key.%s' "$B" "$N" )

    ${B}2. Reverse-proxy /.well-known/mercure to the hub${N}

       The browser must reach the hub on the wiki's own origin, or the ticket cookie
       will not be sent. For nginx, inside ${B}this${N} wiki's server block:

           location ^~ /.well-known/mercure {
               proxy_pass          http://127.0.0.1:$T_PORT;
               proxy_http_version  1.1;
               proxy_set_header    Connection '';
               proxy_set_header    Host \$host;
               proxy_set_header    X-Forwarded-For \$proxy_add_x_forwarded_for;
               proxy_set_header    X-Forwarded-Proto \$scheme;
               proxy_buffering     off;        # or events queue in a buffer
               proxy_cache         off;
               proxy_read_timeout  24h;        # the stream is meant to stay open
               gzip                off;        # never compress private updates (BREACH)
               chunked_transfer_encoding off;
           }

       ${D}Put it BEFORE any 'location ~ /\\.' deny rule — that pattern matches
       /.well-known/mercure, and nginx prefers a regex match over a prefix one.
       The '^~' modifier is what stops it.${N}

       ${D}Each wiki needs its own hostname: the hub's path is fixed at
       /.well-known/mercure, so two wikis under one hostname would share it.${N}

       ${D}Realtime wants HTTP/2, so terminate TLS at the proxy. An SSE stream holds
       one of the browser's ~6 connections per origin for as long as it lives.${N}

    ${B}Handy afterwards${N}

           systemctl status $T_UNIT
           journalctl -u $T_UNIT -f
           sudo $0 <newer-version>   ${D}# upgrades every instance; keys are kept${N}

EOF
