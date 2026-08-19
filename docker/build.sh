#!/bin/bash
# Build the Astucia Wiki image, correctly stamped, every time.
#
#   ./docker/build.sh                                  local build, this machine's arch
#   IMAGE_NAME=you/astucia-wiki ./docker/build.sh       tags ready to docker push
#   PLATFORMS=linux/amd64,linux/arm64 \
#     IMAGE_NAME=you/astucia-wiki ./docker/build.sh     multi-arch, pushed to a registry
#   ./docker/build.sh --no-cache                       extra args go to docker build
#
# Three tags, with different mutability, because only one of them is safe to deploy:
#
#   :sha-<commit>  IMMUTABLE. A commit builds one tree, so this tag means exactly one
#                  image, forever. This is what to run and what to pin. Not created from
#                  a dirty working tree — those edits have no identity to name.
#   :<version>     MOVES. VERSION only changes at release time while the code keeps
#                  moving, so one version tag can name several builds. (During one day of
#                  development :2026.7.43 pointed at four different images.)
#   :latest        MOVES CONSTANTLY. For discovery and casual `docker run` only.
#
# The pinned tag is written to docker/.image-tag, which create_container.sh reads, so the
# deployed container records what it actually runs instead of a moving target.
set -euo pipefail

cd "$(dirname "$0")/.."

IMAGE_NAME="${IMAGE_NAME:-astucia-wiki}"
PLATFORMS="${PLATFORMS:-}"
VERSION="$(tr -d '[:space:]' < VERSION)"

# Revision, with a "-dirty" suffix when the working tree has uncommitted changes: an
# image built from unsaved edits must not claim to be a clean commit. "unknown" when
# there is no git checkout at all (a downloaded tarball, for instance).
CLEAN=yes
if git rev-parse --git-dir >/dev/null 2>&1; then
    REVISION="$(git rev-parse --short HEAD)"
    if ! git diff --quiet HEAD 2>/dev/null; then
        REVISION="${REVISION}-dirty"
        CLEAN=no
    fi
else
    REVISION="unknown"
    CLEAN=no
fi

TAGS=(-t "${IMAGE_NAME}:latest" -t "${IMAGE_NAME}:${VERSION}")
if [ "$CLEAN" = yes ]; then
    SHA_TAG="${IMAGE_NAME}:sha-${REVISION}"
    TAGS+=(-t "$SHA_TAG")
    PINNED="$SHA_TAG"
else
    SHA_TAG=""
    PINNED="${IMAGE_NAME}:${VERSION}"
fi

echo "Building ${IMAGE_NAME}"
echo "  version   ${VERSION}"
echo "  revision  ${REVISION}"
echo "  platforms ${PLATFORMS:-$(docker version --format '{{.Server.Os}}/{{.Server.Arch}}')}"
if [ "$CLEAN" = yes ]; then
    echo "  tags      latest, ${VERSION}, sha-${REVISION}"
else
    echo "  tags      latest, ${VERSION}   (no immutable sha- tag)"
    echo
    echo "  WARNING: the working tree has uncommitted changes, so this build has no"
    echo "           reproducible identity and gets no immutable sha- tag. Commit first"
    echo "           if you intend to deploy, distribute or push it."
fi
echo

# --- Multi-architecture ------------------------------------------------------
# A manifest list cannot live in the classic local image store, so a multi-platform build
# has to go straight to a registry. That needs a real repository name and a builder that
# can cross-build — both checked up front rather than failing halfway through a long build.
if [ -n "$PLATFORMS" ]; then
    if [ "$IMAGE_NAME" = "astucia-wiki" ]; then
        cat >&2 <<EOF
ERROR: a multi-platform build is pushed straight to a registry, so IMAGE_NAME must be a
       repository you can push to, e.g.

  PLATFORMS=$PLATFORMS IMAGE_NAME=youruser/astucia-wiki $0
EOF
        exit 1
    fi
    driver="$(docker buildx inspect 2>/dev/null | awk -F': *' '/^Driver:/{print $2; exit}')"
    if [ "$driver" = "docker" ] || [ -z "$driver" ]; then
        cat >&2 <<'EOF'
ERROR: the active buildx builder cannot build more than one platform (the default
       "docker" driver has no support for it). One-time setup:

  docker buildx create --use --name astucia          # container-driver builder
  docker run --privileged --rm tonistiigi/binfmt --install arm64   # QEMU, per boot
EOF
        exit 1
    fi
    # QEMU handlers live in the kernel (/proc/sys/fs/binfmt_misc), so they are lost on
    # reboot. Registering them is one idempotent command, so just do it rather than
    # failing a ten-minute build or making it a step to remember. Set
    # SKIP_QEMU_SETUP=1 to opt out, or install the qemu-user-static package to have
    # systemd register them at boot instead.
    native="$(docker version --format '{{.Server.Arch}}')"
    for p in ${PLATFORMS//,/ }; do
        arch="${p#*/}"
        [ "$arch" = "$native" ] && continue
        if ls /proc/sys/fs/binfmt_misc/ 2>/dev/null | grep -qi "qemu-"; then continue; fi
        if [ -n "${SKIP_QEMU_SETUP:-}" ]; then
            echo "WARNING: no QEMU handler for ${arch} and SKIP_QEMU_SETUP is set." >&2
            echo "         docker run --privileged --rm tonistiigi/binfmt --install ${arch}" >&2
            continue
        fi
        echo "Registering QEMU for ${arch} (kernel state, lost on reboot)…"
        docker run --privileged --rm tonistiigi/binfmt --install "$arch" >/dev/null 2>&1 || true
        ls /proc/sys/fs/binfmt_misc/ 2>/dev/null | grep -qi "qemu-" \
            || { echo "ERROR: could not register QEMU for ${arch}." >&2
                 echo "       docker run --privileged --rm tonistiigi/binfmt --install ${arch}" >&2
                 exit 1; }
        echo "  done"
        echo
    done

    echo "Building and pushing a manifest list: ${PLATFORMS}"
    echo
    docker buildx build \
        --platform "$PLATFORMS" \
        --build-arg "VERSION=${VERSION}" \
        --build-arg "REVISION=${REVISION}" \
        "${TAGS[@]}" \
        --push \
        "$@" \
        .

    echo
    echo "Published:"
    for t in latest "${VERSION}" ${SHA_TAG:+"sha-${REVISION}"}; do
        docker buildx imagetools inspect "${IMAGE_NAME}:${t}" >/dev/null 2>&1 \
            || { echo "ERROR: ${IMAGE_NAME}:${t} is not in the registry" >&2; exit 1; }
        echo "  ${IMAGE_NAME}:${t}"
    done
    echo
    docker buildx imagetools inspect "$PINNED" | awk '/Platform:/{print "  "$0}' | sort -u
    echo
    echo "Deploy this build:"
    echo "  WIKI_IMAGE=${PINNED} docker compose up -d"
    echo "  (or set IMAGE= in create_container.sh to ${PINNED})"
    exit 0
fi

# --- Single platform, loaded into the local image store -----------------------
docker build \
    --build-arg "VERSION=${VERSION}" \
    --build-arg "REVISION=${REVISION}" \
    "${TAGS[@]}" \
    "$@" \
    .

# Assert every tag resolves. A tag has silently gone missing here before — one that does
# not exist is discovered at `docker push`, or worse, not at all.
CHECK=(latest "${VERSION}")
[ -n "$SHA_TAG" ] && CHECK+=("sha-${REVISION}")
for t in "${CHECK[@]}"; do
    docker image inspect "${IMAGE_NAME}:${t}" >/dev/null 2>&1 \
        || { echo "ERROR: ${IMAGE_NAME}:${t} was not created" >&2; exit 1; }
done

# Read by create_container.sh so a deploy pins the exact build rather than a moving tag.
echo "$PINNED" > "$(dirname "$0")/.image-tag"

echo
docker image ls "${IMAGE_NAME}" --format '  {{.Repository}}:{{.Tag}}  {{.Size}}  {{.CreatedSince}}'
echo
echo "Deploy this build:"
echo "  ./docker/create_container.sh                 # pins ${PINNED}"
echo "  WIKI_IMAGE=${PINNED} docker compose up -d"
if [ "$CLEAN" != yes ]; then
    echo "  (pinned to a MOVING tag, because this build is not reproducible)"
fi
if [ "$IMAGE_NAME" != "astucia-wiki" ]; then
    echo
    echo "Push:"
    [ -n "$SHA_TAG" ] && echo "  docker push ${SHA_TAG}"
    echo "  docker push ${IMAGE_NAME}:${VERSION}"
    echo "  docker push ${IMAGE_NAME}:latest"
    echo
    echo "For a multi-architecture image, build straight to the registry instead:"
    echo "  PLATFORMS=linux/amd64,linux/arm64 IMAGE_NAME=${IMAGE_NAME} $0"
fi
