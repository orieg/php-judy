#!/bin/bash
# Watch the differential harness FAIL against both historical bug classes,
# then pass against the same sources with the defect removed. A green
# harness on a broken library is worse than nothing; this script is the
# proof of detection power, and it is expected to be run inside a gcc
# container (see run-in-docker.sh).
#
#   V1  #131: stock 1.0.5 at gcc -O3   -> Judy1 differential must FAIL
#   V2  same sources at gcc -O2        -> full smoke must PASS
#   V3  #127: ASan-built stock library -> JudyInsArray Count==31 must
#       report global-buffer-overflow at j__{1,L}_LeafWPopToWords
#   V4  same ASan build with the one-line #127 fix applied -> must PASS
#
# Environment: REPO (mounted repo root, default /repo), WORK (scratch,
# default /tmp/diffuzz-validate). Exits non-zero if any expectation is
# not met; prints PASS/FAIL per stage plus the observed evidence lines.
set -u

REPO=${REPO:-/repo}
WORK=${WORK:-/tmp/diffuzz-validate}
SRC="$REPO/libjudy/src"
HDIR="$REPO/tools/differential-fuzz"

fail=0
note() { printf '\n==== %s\n' "$*"; }
verdict() { # verdict <ok:0|1> <label>
    if [ "$1" -eq 0 ]; then printf 'VALIDATION PASS: %s\n' "$2";
    else printf 'VALIDATION FAIL: %s\n' "$2"; fail=1; fi
}

rm -rf "$WORK"
mkdir -p "$WORK"
cp "$HDIR/diffuzz.cpp" "$HDIR/Makefile" "$WORK/"
cd "$WORK"

command -v gcc >/dev/null || { echo "gcc required" >&2; exit 2; }
gcc --version | head -1

# ---------------------------------------------------------------- V1: #131
note "V1: stock 1.0.5, gcc -O3 — the #131 miscompile must be caught"
CC=gcc sh "$HDIR/validation/build-stock.sh" "$SRC" "$WORK/o3" \
    "-O3" 2> "$WORK/o3-build.log"
if grep -q 'aggressive-loop-optimizations' "$WORK/o3-build.log"; then
    echo "corroboration: gcc warned about the UB it exploits:"
    grep -m2 'aggressive-loop-optimizations' "$WORK/o3-build.log" | sed 's/^/  | /'
fi
make -s clean; make -s CXX=g++ JUDY_PREFIX="$WORK/o3" diffuzz
# --no-bulk isolates this stage to the classic APIs: against stock sources
# the bulk sweep would trip #127 first, which is V3's job, not V1's.
./diffuzz smoke --no-bulk > "$WORK/o3-smoke.log" 2>&1
rc=$?
tail -6 "$WORK/o3-smoke.log" | sed 's/^/  | /'
[ $rc -ne 0 ] && grep -q 'DIVERGENCE domain=judy1' "$WORK/o3-smoke.log"
verdict $? "V1 gcc -O3: Judy1 differential FAILED as required (exit $rc)"

# ---------------------------------------------------------------- V2: -O2
note "V2: same sources, gcc -O2 — must be clean (-O2 is load-bearing, #131)"
CC=gcc sh "$HDIR/validation/build-stock.sh" "$SRC" "$WORK/o2" \
    "-O2" 2> "$WORK/o2-build.log"
make -s clean; make -s CXX=g++ JUDY_PREFIX="$WORK/o2" diffuzz
./diffuzz smoke --no-bulk > "$WORK/o2-smoke.log" 2>&1
rc=$?
tail -2 "$WORK/o2-smoke.log" | sed 's/^/  | /'
[ $rc -eq 0 ]
verdict $? "V2 gcc -O2: full smoke passed (exit $rc)"

# ---------------------------------------------------------------- V3: #127
note "V3: ASan-built stock library — JudyInsArray Count==31 off-by-one (#127)"
CC=gcc sh "$HDIR/validation/build-stock.sh" "$SRC" "$WORK/asan" \
    "-O1 -g -fsanitize=address" 2> "$WORK/asan-build.log"
make -s clean; make -s CXX=g++ JUDY_PREFIX="$WORK/asan" diffuzz-san
ok=0
for dom in judy1 judyl; do
    # bulk phase runs first in every cell; Count==31 is in the sweep
    ./diffuzz-san one $dom uniform 0x1 256 > "$WORK/asan-$dom.log" 2>&1
    rc=$?
    if [ $rc -ne 0 ] && grep -q 'global-buffer-overflow' "$WORK/asan-$dom.log" \
        && grep -q 'PopToWords' "$WORK/asan-$dom.log"; then
        echo "  $dom: ASan caught it (exit $rc):"
        grep -m1 -E 'ERROR: AddressSanitizer' "$WORK/asan-$dom.log" | sed 's/^/  | /'
        grep -m1 'PopToWords' "$WORK/asan-$dom.log" | sed 's/^/  | /'
    else
        echo "  $dom: expected global-buffer-overflow at PopToWords, got exit $rc"
        tail -5 "$WORK/asan-$dom.log" | sed 's/^/  | /'
        ok=1
    fi
done
verdict $ok "V3 ASan stock: bulk sweep reported the #127 overflow in both domains"

# ------------------------------------------------------------- V4: #127 fix
note "V4: same ASan build + the one-line #127 fix — must be clean"
rm -rf "$WORK/src-fixed"
cp -r "$SRC" "$WORK/src-fixed"
sed -i 's/j__udyAllocJLW(Count + 1)/j__udyAllocJLW(Count)/' \
    "$WORK/src-fixed/JudyCommon/JudyInsArray.c"
grep -q 'j__udyAllocJLW(Count)' "$WORK/src-fixed/JudyCommon/JudyInsArray.c" \
    || { echo "patch did not apply" >&2; exit 2; }
CC=gcc sh "$HDIR/validation/build-stock.sh" "$WORK/src-fixed" "$WORK/asan-fixed" \
    "-O1 -g -fsanitize=address" 2> "$WORK/asan-fixed-build.log"
make -s clean; make -s CXX=g++ JUDY_PREFIX="$WORK/asan-fixed" diffuzz-san
./diffuzz-san smoke > "$WORK/asan-fixed-smoke.log" 2>&1
rc=$?
tail -2 "$WORK/asan-fixed-smoke.log" | sed 's/^/  | /'
[ $rc -eq 0 ]
verdict $? "V4 ASan + fix: full smoke incl. bulk sweep passed (exit $rc)"

note "result"
if [ $fail -eq 0 ]; then
    echo "ALL VALIDATIONS PASSED: the harness demonstrably detects both bug classes."
else
    echo "VALIDATION FAILURES PRESENT — see logs under $WORK"
fi
exit $fail
