#!/bin/sh
# Run the regression gate one or more times on one platform.
#
#   run-gate.sh --platform KEY --arms DIR --out-dir DIR \
#               [--with-b] [--toolchain STR] [--provenance STR] [--gate]
#
# Reads BENCH_ROUNDS / BENCH_SIZE / BENCH_REPEATS / BENCH_GROUPS /
# BENCH_MEM_SIZES from the environment (see .github/workflows/bench-gate.yml).
#
# Why repeats exist
# -----------------
# BENCH_REPEATS > 1 runs the gate several times back to back and then feeds
# every run JSON to `bench-gate.php --derive`, which measures how far each cell's
# ratio moved between runs WHEN THE CODE DID NOT CHANGE. That spread is the
# false-positive rate of any threshold below it, and it is where the numbers in
# `baselines/arm-ratios.json`'s `noise` block come from.
#
# Repeats inside ONE job measure short-term, same-runner noise. The gate's real
# adversary is CROSS-runner variance — the baseline was recorded on a different
# VM on a different day. So the derivation that sets a shipped threshold must
# pool runs from SEVERAL dispatches, not just several repeats inside one. Both
# are collected: the artifacts from each dispatch are combined with `--derive`
# over the whole set.
set -eu

PLATFORM=""; ARMS=""; OUTDIR=""; TOOLCHAIN=""; PROVENANCE=""; WITH_B=0; GATE=0
while [ $# -gt 0 ]; do
    case "$1" in
        --platform)   PLATFORM=$2; shift 2 ;;
        --arms)       ARMS=$2; shift 2 ;;
        --out-dir)    OUTDIR=$2; shift 2 ;;
        --toolchain)  TOOLCHAIN=$2; shift 2 ;;
        --provenance) PROVENANCE=$2; shift 2 ;;
        --with-b)     WITH_B=1; shift ;;
        --gate)       GATE=1; shift ;;
        "" )          shift ;;
        *) echo "run-gate.sh: unknown argument '$1'" >&2; exit 2 ;;
    esac
done
[ -n "$PLATFORM" ] || { echo "--platform is required" >&2; exit 2; }
[ -n "$ARMS" ]     || { echo "--arms is required" >&2; exit 2; }
[ -n "$OUTDIR" ]   || { echo "--out-dir is required" >&2; exit 2; }

REPO=$(cd "$(dirname "$0")/../.." && pwd)
mkdir -p "$OUTDIR"

# Host exclusivity, on a host that HAS other tenants.
#
# tools/bench-lock.sh is the repo's mutex, and it exists because two php-judy
# campaigns once ran concurrently on honeycomb, both passed the loadavg < N/2
# check at loadavg 2.9 on 24 cores, and corrupted each other anyway. Any
# campaign in this repo is supposed to take it.
#
# It is deliberately NOT taken on a CI runner: each GitHub job gets its own
# ephemeral VM, so there is no shared host to exclude anyone from, and a lock
# file in that VM's /var/tmp would be a no-op that only obscured the intent.
# Running this script by hand on the bench host DOES take it.
if [ -z "${CI:-}" ] && [ -f "$REPO/tools/bench-lock.sh" ]; then
    # shellcheck disable=SC1091
    . "$REPO/tools/bench-lock.sh"
    bench_lock_acquire "bench-gate" "cross-platform regression gate on $PLATFORM" || exit 3
fi

ROUNDS=${BENCH_ROUNDS:-5}
SIZE=${BENCH_SIZE:-300000}
REPEATS=${BENCH_REPEATS:-1}
# NOT named GROUPS: that is a special read-only variable in bash, which is what
# /bin/sh is on macOS, and assigning to it kills the script with no message.
# busybox ash (Alpine) and dash accept it happily, so the bug would have shown
# up on exactly one of the four platforms this gate exists to cover.
BENCH_GROUP_LIST=${BENCH_GROUPS:-core.int,core.str,api.setops}
MEM_SIZES=${BENCH_MEM_SIZES:-1000000}

# The arm-S verification manifest travels with the results: a reader must be able
# to see that the reference arm really was unpatched ON THIS PLATFORM, not take
# it on trust from a Linux run.
for f in arm-s-manifest.json census-S.json census-C.json; do
    if [ -f "$ARMS/$f" ]; then cp "$ARMS/$f" "$OUTDIR/"; fi
done

# Arm arguments are accumulated into "$@" rather than into a string: a path or a
# toolchain description containing a space must survive intact, and unquoted
# word-splitting of a flat string would tear it apart.
set --
for role in C S B; do
    if [ "$role" = "B" ] && [ "$WITH_B" != "1" ]; then continue; fi
    for so in "$ARMS"/judy-$role-*.so "$ARMS"/judy-$role-*.dll; do
        # A glob that matches nothing expands to itself; -f rejects that.
        if [ -f "$so" ]; then set -- "$@" --arm "$role=$so"; fi
    done
done
if [ $# -eq 0 ]; then
    echo "run-gate.sh: no arm objects found under $ARMS" >&2
    exit 2
fi

set -- "$@" --platform "$PLATFORM" \
          --rounds "$ROUNDS" --size "$SIZE" \
          --groups "$BENCH_GROUP_LIST" --mem-sizes "$MEM_SIZES"
if [ -n "$TOOLCHAIN" ];   then set -- "$@" --toolchain "$TOOLCHAIN"; fi
if [ -n "$PROVENANCE" ];  then set -- "$@" --provenance "$PROVENANCE"; fi
if [ -f "$REPO/baselines/arm-ratios.json" ]; then
    set -- "$@" --baseline "$REPO/baselines/arm-ratios.json"
fi

RUNS=""
STATUS=0
i=1
while [ "$i" -le "$REPEATS" ]; do
    OUT="$OUTDIR/gate-$PLATFORM-$i.json"
    echo "=== gate run $i/$REPEATS on $PLATFORM ==="
    # `--gate` only on the final repeat: with several repeats the point is to
    # measure spread, and failing on repeat 1 would throw away the very data the
    # derivation needs.
    if [ "$GATE" = "1" ] && [ "$i" = "$REPEATS" ]; then
        set -- "$@" --gate
    fi

    if php "$REPO/scripts/bench-gate.php" "$@" \
        --out "$OUT" --stability-csv "$OUTDIR/stability-$PLATFORM-$i.csv"
    then
        :
    else
        STATUS=$?
    fi

    # The repo's shared contention detector, run over the CSV contract the gate
    # emits for it. It uses the untouched arm S as a canary and asks whether the
    # ABSOLUTE timings held still — a different question from the one the gate
    # answers, because a paired ratio survives drift that moves both arms.
    #
    # So: recorded everywhere, ENFORCED only off CI. On a dedicated host an
    # unstable canary means the absolute numbers are not quotable and the run
    # should be repeated. On a shared runner it is expected to wobble, and
    # failing the job for it would be failing it for something the gate's
    # verdicts do not depend on.
    # "the guard could not run" and "the guard says contaminated" are different
    # facts and must not share an exit path: a slim image without python3 would
    # otherwise report every run as contaminated, which is how a guard stops
    # being believed.
    if [ ! -f "$REPO/tools/bench-stability.py" ]; then
        echo "baseline-stability guard: not present in this checkout"
    elif ! command -v python3 >/dev/null 2>&1; then
        echo "baseline-stability guard: UNAVAILABLE (no python3 on this image)" \
            | tee "$OUTDIR/stability-$PLATFORM-$i.txt"
    elif python3 "$REPO/tools/bench-stability.py" --tol 0.15 \
            "$OUTDIR/stability-$PLATFORM-$i.csv" \
            > "$OUTDIR/stability-$PLATFORM-$i.txt" 2>&1; then
        echo "baseline-stability guard: stable"
    else
        echo "baseline-stability guard: CONTAMINATED (absolute times moved)"
        tail -3 "$OUTDIR/stability-$PLATFORM-$i.txt"
        # Enforced only off CI. On a dedicated host an unstable canary means the
        # absolute numbers are not quotable and the run should be repeated; on a
        # shared runner it is expected to wobble, and the gate's verdicts are
        # ratios, which survive drift that moves both arms together.
        if [ -z "${CI:-}" ]; then STATUS=1; fi
    fi

    RUNS="${RUNS:+$RUNS,}$OUT"
    i=$((i + 1))
done

# Threshold derivation, when there is more than one run to derive from.
if [ "$REPEATS" -ge 2 ]; then
    echo "=== deriving the empirical noise floor from $REPEATS same-commit runs ==="
    php "$REPO/scripts/bench-gate.php" --derive "$RUNS" > "$OUTDIR/derived-$PLATFORM.json"
    cat "$OUTDIR/derived-$PLATFORM.json"
fi

exit "$STATUS"
