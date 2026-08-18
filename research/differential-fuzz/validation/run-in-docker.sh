#!/bin/sh
# Host-side wrapper: run validate.sh inside a gcc container against the
# repo's pristine libjudy/ import. The repo is mounted read-only; all
# build products go to the container's /tmp.
#
#   validation/run-in-docker.sh [image]
#
# Default image gcc:15 matches the compiler that issue #131 was reproduced
# with (the miscompile is present in earlier gcc too, via
# -faggressive-loop-optimizations at -O3).
set -eu

IMG=${1:-gcc:15}
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
[ -f "$ROOT/libjudy/src/Judy.h" ] || {
    echo "no libjudy/src/Judy.h under $ROOT — run from a checkout with the" \
         "Stage 0 pristine import" >&2
    exit 2
}

exec docker run --rm -v "$ROOT":/repo:ro "$IMG" \
    bash /repo/research/differential-fuzz/validation/validate.sh
