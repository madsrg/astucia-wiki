#!/bin/sh
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
# Astucia Wiki container entrypoint.
#
# Prepares the things a flat-file wiki needs before nginx and PHP start: the data
# directories, a config.php, correct ownership, and the crontab. Idempotent — safe
# on every restart, not just the first.
set -eu

log() { echo "[entrypoint] $*"; }

APP_DIR=/var/www/html
PAGES_DIR="${PAGES_DIR:-/data/pages/}"
WIKI_SYSTEM_DATA="${WIKI_SYSTEM_DATA:-/data/system/}"
LOG_DIR="${LOG_DIR:-/data/logs/}"

# ---------------------------------------------------------------------------
# Who should own the content?
#
# A bind-mounted host directory belongs to whoever created it (often 1000), and
# chowning it to the image's www-data (82) would take it away from the very user
# who mounted it in order to edit files with their own tools. So when PUID/PGID
# are not given explicitly, adopt the owner of the mounted directory. A named
# volume arrives root-owned, which is the signal to use the image default.
# ---------------------------------------------------------------------------
if [ -z "${PUID:-}" ] || [ -z "${PGID:-}" ]; then
    probe=""
    for cand in "$PAGES_DIR" "$(dirname "${PAGES_DIR%/}")" /data; do
        [ -d "$cand" ] && { probe="$cand"; break; }
    done
    if [ -n "$probe" ]; then
        owner_uid=$(stat -c %u "$probe")
        owner_gid=$(stat -c %g "$probe")
        if [ "$owner_uid" != "0" ]; then
            PUID="${PUID:-$owner_uid}"
            PGID="${PGID:-$owner_gid}"
            log "adopting the owner of $probe (uid $PUID, gid $PGID) so the host keeps write access"
        fi
    fi
fi
PUID="${PUID:-82}"
PGID="${PGID:-82}"

# ---------------------------------------------------------------------------
# 1. Match the container user to the host's, for bind mounts
#
# The alpine PHP image's www-data is uid 82, but a host directory is owned by
# whoever created it (often 1000). Without this, a bind-mounted PAGES_DIR is
# read-only to the wiki and every save fails. Named volumes don't have the
# problem, so the default is a no-op.
# ---------------------------------------------------------------------------
if [ "$PGID" != "$(getent group www-data | cut -d: -f3)" ]; then
    log "remapping group www-data -> gid $PGID"
    groupmod -o -g "$PGID" www-data 2>/dev/null || \
        log "WARNING: could not set gid $PGID (is shadow installed?)"
fi
if [ "$PUID" != "$(id -u www-data)" ]; then
    log "remapping user www-data -> uid $PUID"
    usermod -o -u "$PUID" www-data 2>/dev/null || \
        log "WARNING: could not set uid $PUID (is shadow installed?)"
fi

# ---------------------------------------------------------------------------
# 1b. Timezone
#
# Three separate consumers, and TZ alone satisfies none of them properly: PHP
# ignores TZ and uses its own date.timezone (default UTC), while crond reads
# /etc/localtime. Agent job schedules are evaluated in PHP's timezone, so a
# mismatch means "run daily at 08:00" fires at the wrong hour — set all of them.
# ---------------------------------------------------------------------------
if [ -n "${TZ:-}" ] && [ -f "/usr/share/zoneinfo/$TZ" ]; then
    log "timezone: $TZ (system + PHP + cron)"
    ln -snf "/usr/share/zoneinfo/$TZ" /etc/localtime
    echo "$TZ" > /etc/timezone
    printf 'date.timezone=%s\n' "$TZ" > /usr/local/etc/php/conf.d/zz-timezone.ini
elif [ -n "${TZ:-}" ]; then
    log "WARNING: TZ='$TZ' is not a known zoneinfo name — falling back to UTC"
fi

# ---------------------------------------------------------------------------
# 2. Data directories
#
# All three live under the /data volume, outside the web root: WIKI_SYSTEM_DATA
# holds users.json, the job queue and search.sqlite, none of which should ever be
# reachable over HTTP.
# ---------------------------------------------------------------------------
for d in "$PAGES_DIR" "$WIKI_SYSTEM_DATA" "$LOG_DIR"; do
    if [ ! -d "$d" ]; then
        log "creating $d"
        mkdir -p "$d"
    fi
done

# ---------------------------------------------------------------------------
# 2b. First run
#
# Nothing is seeded here on purpose. The application creates a "Main" Space with a
# start page the first time the UI loads (see wiki_ensure_default_space in api.php).
# Writing a Main.md at the root instead would leave a page the UI can never open
# again, because once any Space exists the root is no longer selectable.
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# 3. config.php
#
# A mounted config.php always wins, so an existing file-based setup keeps working.
# Otherwise generate one that reads the environment.
# ---------------------------------------------------------------------------
if [ -f "$APP_DIR/config.php" ]; then
    log "using the existing $APP_DIR/config.php"
else
    log "writing an environment-driven config.php"
    cp /usr/local/share/astucia/config.docker.php "$APP_DIR/config.php"
fi
# Fail loudly and early rather than serving a half-broken wiki.
if ! php -l "$APP_DIR/config.php" > /dev/null 2>&1; then
    log "FATAL: config.php is not valid PHP"
    php -l "$APP_DIR/config.php" || true
    exit 1
fi

# ---------------------------------------------------------------------------
# 4. Ownership
#
# Only the data volume and the few app paths that are written at runtime; the
# code itself stays root-owned and read-only to the web user.
# ---------------------------------------------------------------------------
log "setting ownership to www-data ($(id -u www-data):$(getent group www-data | cut -d: -f3))"
chown -R www-data:www-data "$PAGES_DIR" "$WIKI_SYSTEM_DATA" "$LOG_DIR"
chown www-data:www-data "$APP_DIR/config.php"
chmod 640 "$APP_DIR/config.php"

# ---------------------------------------------------------------------------
# 5. Crontab
#
# Written from AGENT_JOB_RUNNER_INTERVAL_MINUTES, which config.php reads too, so
# the schedule and the "/aiJob starts in about N minutes" estimate cannot drift
# apart — a mismatch there is how you get an estimate that never comes true.
# ---------------------------------------------------------------------------
CRON_FILE=/etc/crontabs/www-data
if [ "${ENABLE_CRON:-true}" = "true" ]; then
    IVL="${AGENT_JOB_RUNNER_INTERVAL_MINUTES:-15}"
    case "$IVL" in ''|*[!0-9]*) IVL=15 ;; esac
    [ "$IVL" -ge 1 ] 2>/dev/null || IVL=15
    [ "$IVL" -le 59 ] 2>/dev/null || IVL=59
    DIGEST_HOUR="${DAILY_DIGEST_HOUR:-7}"
    log "cron: agent jobs every ${IVL}m, daily digest at ${DIGEST_HOUR}:00"
    mkdir -p /etc/crontabs
    cat > "$CRON_FILE" <<EOF
# Generated by the container entrypoint — edits are overwritten on restart.
*/$IVL * * * * php $APP_DIR/run_ai_agent_jobs.php >> ${LOG_DIR}cron-agent-jobs.log 2>&1
0 $DIGEST_HOUR * * * php $APP_DIR/run_daily_digest.php >> ${LOG_DIR}cron-digest.log 2>&1
EOF
    # Must stay ROOT-owned: busybox crond silently ignores a crontab file owned by
    # anyone else — no error, no log line, the jobs simply never run. The FILENAME is
    # what decides the user they run as, so they still execute as www-data.
    chown root:root "$CRON_FILE"
    chmod 600 "$CRON_FILE"
else
    log "cron disabled (ENABLE_CRON=$ENABLE_CRON) — scheduled agent jobs and the digest will not run"
    rm -f "$CRON_FILE"
fi

# ---------------------------------------------------------------------------
# 6. Git identity for optional page version history
#
# git_helpers.php commits as the editing user, but git refuses to commit at all
# without a fallback identity, and marks a volume owned by another uid as unsafe.
# ---------------------------------------------------------------------------
if [ -d "$PAGES_DIR/.git" ]; then
    log "content directory is a git repository — configuring a safe.directory entry"
    git config --system --add safe.directory "${PAGES_DIR%/}" 2>/dev/null || true
    git config --system user.email "${GIT_AUTHOR_EMAIL:-wiki@localhost}" 2>/dev/null || true
    git config --system user.name  "${GIT_AUTHOR_NAME:-Astucia Wiki}" 2>/dev/null || true
fi

log "startup complete — handing over to: $*"
exec "$@"
