#!/bin/sh
# Build the benchmark arms from ONE source tree with ONE toolchain.
#
# This is the POSIX half of the arm-construction recipe (Linux glibc, Alpine
# musl, macOS). Windows builds the same arms through config.w32 and the
# php-windows-builder action; see .github/workflows/bench-gate.yml.
#
#   ./research/three-arm-benchmark/build-arms.sh <outdir> [copies]
#
# Produces, under <outdir>:
#   judy-C-1.so .. judy-C-N.so   arm C — the shipped bundled tree
#   judy-S-1.so .. judy-S-N.so   arm S — the pristine-static reference arm
#   arm-s-manifest.json          the arm-S verification record
#
# `copies` (default 2) is how many INDEPENDENTLY LINKED builds of each arm to
# produce. More than one is not redundancy: bench-threearm.php and
# bench-gate.php rotate them across rounds and demote any cell whose per-build
# spread exceeds its own delta, which is what separates a real library effect
# from the page/cache layout of one particular binary. Two also gives the
# C1-vs-C2 rebuild control, which is the run's memory-access-matched noise
# measurement — a PHP-array control cannot see LLC/bandwidth contention.
#
# Every arm is built with the SAME extension sources, the SAME compiler and the
# SAME PHP, and with `--with-judy` left at its default (bundled/static). Arm S
# differs from arm C only in the contents of libjudy/. A distro- or
# PECL-installed judy.so is NOT a valid arm: a prior comparison did that and its
# ~9.4% "win" turned out to be toolchain provenance (BENCHMARK.md, FINDINGS
# §11.10).
set -eu

OUT=${1:?usage: build-arms.sh <outdir> [copies]}
COPIES=${2:-2}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
WORK=${BENCH_ARM_WORKDIR:-${TMPDIR:-/tmp}/php-judy-arms.$$}

mkdir -p "$OUT"
OUT=$(cd "$OUT" && pwd)
mkdir -p "$WORK"

PHPIZE=${PHPIZE:-phpize}
PHP=${PHP:-php}

# `make -j` is safe here and the build is the dominant cost on a CI runner.
JOBS=$( (nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 2) )

build_one() {
    src=$1; label=$2; n=$3
    tree="$WORK/$label-$n"
    rm -rf "$tree"
    cp -R "$src" "$tree"
    # A stale object tree from the source copy would defeat the point.
    ( cd "$tree" \
      && find . -name '*.lo' -delete 2>/dev/null || true )
    ( cd "$tree" \
      && rm -f Makefile Makefile.objects Makefile.fragments config.h config.status \
      && rm -rf modules .libs \
      && "$PHPIZE" >/dev/null \
      && ./configure >"$tree/configure.log" 2>&1 \
      && make -j"$JOBS" >"$tree/build.log" 2>&1 )
    cp "$tree/modules/judy.so" "$OUT/judy-$label-$n.so"
    echo "  built arm $label copy $n -> $OUT/judy-$label-$n.so"
}

echo "php-judy: building benchmark arms ($COPIES independently linked copies each)"
echo "  repo   : $REPO"
echo "  php    : $($PHP -r 'echo PHP_VERSION;') at $($PHP -r 'echo PHP_BINARY;')"
echo "  out    : $OUT"

# ── Arm C: the shipped tree, git-tracked files only ─────────────────────────
CSRC="$WORK/src-C"
rm -rf "$CSRC"; mkdir -p "$CSRC"
git -C "$REPO" archive --format=tar HEAD | tar -x -C "$CSRC"

# ── Arm S: the pristine-static reference arm ────────────────────────────────
SSRC="$WORK/src-S"
"$PHP" "$REPO/scripts/bench-arm-s.php" \
    --repo "$REPO" --dest "$SSRC" --manifest "$OUT/arm-s-manifest.json"

i=1
while [ "$i" -le "$COPIES" ]; do
    build_one "$CSRC" C "$i"
    build_one "$SSRC" S "$i"
    i=$((i + 1))
done

# Instruction-level cross-check. Informational on arm64 (where O1 lowers to
# `cnt` and O3 to `rev`, so the x86 mnemonics say nothing); the operative
# unpatched evidence is the source-hash manifest written above.
"$PHP" "$REPO/scripts/bench-arm-s.php" --verify-so "$OUT/judy-S-1.so" > "$OUT/census-S.json" || true
"$PHP" "$REPO/scripts/bench-arm-s.php" --verify-so "$OUT/judy-C-1.so" > "$OUT/census-C.json" || true

echo "arms built. arm-S verification: $($PHP -r '
    $j = json_decode(file_get_contents($argv[1]), true);
    echo $j["verdict"] ?? "?";' "$OUT/arm-s-manifest.json")"
rm -rf "$WORK"
