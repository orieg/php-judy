#!/bin/sh
# Build every harness under research/ warning-free, then run a sanitized smoke
# pass across the parameter grid.
#
# Why this exists: research/ is not part of the extension build. Nothing
# compiled it and nothing ran it, and that is how a generator came to ignore
# its own length argument for years, and how a documented probe path
# (issue #118) shipped without ever having executed. See issue #122.
#
# The grid matters more than the size. Every historical defect here was
# reachable at n = 1000; none of them were reachable at the single
# (corpus, keylen, absent-key) point the harnesses were usually run at.
#
#   research/ci-smoke.sh [n]        n defaults to 1000
#
# Honours CC and JUDY_PREFIX. ASAN_OPTIONS/UBSAN_OPTIONS are set only if the
# caller has not already set them.
set -eu

N="${1:-1000}"
CC="${CC:-cc}"
case "$(uname -s)" in
    Darwin) JUDY_PREFIX="${JUDY_PREFIX:-/opt/homebrew}" ;;
    *)      JUDY_PREFIX="${JUDY_PREFIX:-/usr}" ;;
esac

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
BUILD=$(mktemp -d)
trap 'rm -rf "$BUILD"' EXIT

WARN="-Wall -Wextra -Werror"
CPPF="-I$JUDY_PREFIX/include"
LDF="-L$JUDY_PREFIX/lib -lJudy"
SAN="-fsanitize=address,undefined -fno-sanitize-recover=all -fno-omit-frame-pointer"

export ASAN_OPTIONS="${ASAN_OPTIONS:-abort_on_error=0}"
export UBSAN_OPTIONS="${UBSAN_OPTIONS:-print_stacktrace=1}"

fail=0
say() { printf '\n== %s\n' "$*"; }

# Run one configuration; on a non-zero exit print the command and its output.
# Sanitizer diagnostics go to stderr, so both streams are captured.
smoke() {
    if out=$("$@" 2>&1); then
        :
    else
        printf '  FAIL (exit %s): %s\n' "$?" "$*"
        printf '%s\n' "$out" | sed 's/^/    | /'
        fail=1
    fi
}

# ---------------------------------------------------------------- build gate
say "warning gate: -Wall -Wextra -Werror"
for src in research/iteration-cost/iterbench.c \
           research/write-probe-cost/probebench.c \
           research/backend-comparison/amdahl.c; do
    printf '  %s\n' "$src"
    # shellcheck disable=SC2086
    $CC -O2 $WARN $CPPF -o "$BUILD/$(basename "$src" .c)" "$ROOT/$src" $LDF
done

# research/shm-arena has its own Makefile (static-archive link, -lrt/-lpthread,
# platform feature macros), so it is built through that rather than reproduced
# here. Built in a copy so the checkout stays clean. Compile only: the gates
# fork, kill writers mid-write and probe robust mutexes, which is a feasibility
# study rather than a smoke test.
printf '  research/shm-arena (via its Makefile, compile only)\n'
cp -R "$ROOT/research/shm-arena" "$BUILD/shm-arena"
make -C "$BUILD/shm-arena" \
     CFLAGS="-std=c11 -O2 -g $WARN -Wno-unused-result" >/dev/null

# research/backend-comparison/cmp.c is deliberately not built: it includes
# "art.h" from libart, which README.md says must be cloned alongside and is not
# vendored. Building it here would mean vendoring a dependency into a tree
# whose whole point is that nothing in it ships.
printf '  research/backend-comparison/cmp.c SKIPPED (needs libart, not vendored)\n'

# ------------------------------------------------------------- sanitized run
say "sanitized build (ASan + UBSan)"
# shellcheck disable=SC2086
$CC -O1 -g $SAN $WARN $CPPF -o "$BUILD/iterbench-san" \
    "$ROOT/research/iteration-cost/iterbench.c" $LDF
# shellcheck disable=SC2086
$CC -O1 -g $SAN $WARN $CPPF -o "$BUILD/probebench-san" \
    "$ROOT/research/write-probe-cost/probebench.c" $LDF

# Key lengths bracket every boundary the generators switch on: below 8 (the
# ADAPTIVE/SSO packed path, and the machine-word step PR #139 found), around
# 16 (LONGKEY_MIN, where the struct corpus changes shape), and above it. The
# short half is the half that had never executed.
ITER_STRUCT="4 5 6 7 8 9 12 15 16 17 24 40"
ITER_RAND="2 3 4 5 6 7 8 9 12 15 16 17 24 40"
ITER_VARLEN="4 5 6 7 8 12 15 16 17 24 40"

PROBE_STRUCT="4 5 6 7 8 9 12 15 16 17 24"
PROBE_RAND="3 4 5 6 7 8 9 12 15 16 17 24"
PROBE_VARLEN="4 6 8 12 15 16 17 24"
PROBE_ABSENT="offset shallow mid deep last"

say "iterbench smoke (n=$N)"
for corpus in struct rand varlen; do
    case "$corpus" in
        struct) lens="$ITER_STRUCT" ;;
        rand)   lens="$ITER_RAND" ;;
        varlen) lens="$ITER_VARLEN" ;;
    esac
    printf '  %-7s keylen: %s\n' "$corpus" "$lens"
    for k in $lens; do
        smoke "$BUILD/iterbench-san" "$N" "$k" 1 "$corpus"
    done
done

say "probebench smoke (n=$N, every absent-key divergence)"
for corpus in struct rand varlen; do
    case "$corpus" in
        struct) lens="$PROBE_STRUCT" ;;
        rand)   lens="$PROBE_RAND" ;;
        varlen) lens="$PROBE_VARLEN" ;;
    esac
    printf '  %-7s keylen: %s\n' "$corpus" "$lens"
    for k in $lens; do
        for d in $PROBE_ABSENT; do
            smoke "$BUILD/probebench-san" "$N" "$k" 1 "$corpus" "$d"
        done
    done
done

# A generator that emits nothing would sail through the grid above, so make one
# configuration prove it produced keys of the length it was asked for.
say "self-check: the grid is actually running the harnesses"
"$BUILD/probebench-san" "$N" 6 1 struct shallow 2>/dev/null | grep -q 'JLG hit (SSO packed)' \
    || { printf '  FAIL: the SSO probe row is missing from a keylen 6 run\n'; fail=1; }
"$BUILD/iterbench-san" "$N" 16 1 rand 2>/dev/null | grep -q 'corpus=rand' \
    || { printf '  FAIL: iterbench did not report the corpus it was given\n'; fail=1; }

if [ "$fail" -ne 0 ]; then
    printf '\n== research/ smoke pass FAILED\n'
    exit 1
fi
printf '\n== research/ smoke pass OK\n'
