#!/bin/bash
# Build the Astucia Wiki image, correctly stamped, every time.
#
#   ./docker/build.sh                          -> astucia-wiki:latest + astucia-wiki:<version>
#   IMAGE_NAME=madsrg/astucia-wiki ./docker/build.sh   -> ready to docker push
#   ./docker/build.sh --no-cache               -> extra args are passed to docker build
#
# Two tags on purpose. ":latest" is what docker-compose.yml and create_container.sh
# reference, so a rebuild is picked up without editing anything. ":<version>" accumulates,
# so the local image repository keeps a history you can roll back to:
#
#   docker image ls astucia-wiki
#
# Doing this by hand is how an image ends up claiming a version it does not contain —
# the reason this script exists.
set -euo pipefail

cd "$(dirname "$0")/.."

IMAGE_NAME="${IMAGE_NAME:-astucia-wiki}"
VERSION="$(tr -d '[:space:]' < VERSION)"

# Revision, with a "-dirty" suffix when the working tree has uncommitted changes: an
# image built from unsaved edits must not claim to be a clean commit. "unknown" when
# there is no git checkout at all (a downloaded tarball, for instance).
if git rev-parse --git-dir >/dev/null 2>&1; then
    REVISION="$(git rev-parse --short HEAD)"
    git diff --quiet HEAD 2>/dev/null || REVISION="${REVISION}-dirty"
else
    REVISION="unknown"
fi

echo "Building ${IMAGE_NAME}:latest and ${IMAGE_NAME}:${VERSION}"
echo "  version  ${VERSION}"
echo "  revision ${REVISION}"
case "$REVISION" in
    *-dirty) echo "  NOTE: the working tree has uncommitted changes" ;;
esac
echo

docker build \
    --build-arg "VERSION=${VERSION}" \
    --build-arg "REVISION=${REVISION}" \
    -t "${IMAGE_NAME}:latest" \
    -t "${IMAGE_NAME}:${VERSION}" \
    "$@" \
    .

echo
docker image ls "${IMAGE_NAME}" --format '  {{.Repository}}:{{.Tag}}  {{.Size}}  {{.CreatedSince}}'
echo
echo "Run it with:  ./docker/create_container.sh   (or: docker compose up -d)"
if [ "$IMAGE_NAME" != "astucia-wiki" ]; then
    echo "Push it with: docker push ${IMAGE_NAME}:${VERSION} && docker push ${IMAGE_NAME}:latest"
fi
