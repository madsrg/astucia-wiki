#!/bin/bash
# Create and start the Astucia Wiki container.
#
# All wiki data lives in a directory on the host, so it is trivial to back up and
# survives deleting or replacing the container. Edit the two paths below, then run
# this once. To upgrade later, see "Upgrading" in DOCKER.md — you do NOT run this
# again (the name would clash); you remove the container and re-run it.
set -euo pipefail

# --- Settings -----------------------------------------------------------------

NAME="astucia-wiki"                       # container name
IMAGE="astucia-wiki:local"                # see "Build" in DOCKER.md (pass VERSION/REVISION build args)
PORT="8080"                               # host port -> container port 80
DATA_DIR="/srv/astucia-wiki/data"         # holds pages/, system/ and logs/
ENV_FILE="/srv/astucia-wiki/wiki.env"     # copied from docker/wiki.env.example
MEMORY="1g"                               # container memory ceiling

# --- Checks -------------------------------------------------------------------

if [ ! -f "$ENV_FILE" ]; then
    echo "No env file at $ENV_FILE" >&2
    echo "Create it with:  cp docker/wiki.env.example \"$ENV_FILE\"" >&2
    exit 1
fi
if docker ps -a --format '{{.Names}}' | grep -qx "$NAME"; then
    echo "A container named '$NAME' already exists. Remove it first:" >&2
    echo "  docker rm -f $NAME" >&2
    exit 1
fi

# Created as the invoking user, which is what the container then adopts, so files
# written by the wiki stay editable from the host.
mkdir -p "$DATA_DIR"

# --- Run ----------------------------------------------------------------------
#
#   --restart=always   comes back after a reboot or a crash
#   -v $DATA_DIR:/data everything that must survive lives on the host
#   --env-file         all configuration in one place (see wiki.env.example)
#   -m                 memory ceiling; PHP-FPM children are capped at 512M each,
#                      so leave at least 1g unless you also lower memory_limit
#
# No -it: the container is a daemon and needs no TTY. Use `docker exec -it` when
# you want a shell.

docker run -d \
    --name "$NAME" \
    --restart=always \
    -p "${PORT}:80" \
    -v "${DATA_DIR}:/data" \
    --env-file "$ENV_FILE" \
    -m "$MEMORY" \
    "$IMAGE"

echo
echo "Started '$NAME'. The wiki is at http://localhost:${PORT}"
echo "  data:   $DATA_DIR"
echo "  config: $ENV_FILE"
echo "  logs:   docker logs -f $NAME     (and ${DATA_DIR}/logs/)"
echo
echo "It may take a few seconds to report healthy:"
echo "  docker inspect -f '{{.State.Health.Status}}' $NAME"
