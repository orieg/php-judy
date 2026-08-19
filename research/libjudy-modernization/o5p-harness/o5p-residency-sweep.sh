#!/bin/bash
# o5p-residency-sweep.sh -- re-derive the serial-fallback threshold under
# the CORRECTED serial baseline.
#
# The shipped cJL_MULTIGET_SERIAL_POP1 = 262144 was derived against the
# handicapped baseline (probe keys computed in a dependent chain inside
# the timed loop). Under the corrected baseline the batched path LOSES on
# wdense at pop 1e6 (x0.90) while WINNING on wsparse at the same
# population (x1.57) -- so POPULATION is the wrong axis. The distinguishing
# variable is residency: a dense 1e6 tree fits L3, a sparse one does not.
# JLMU (jpm_TotalMemWords) is O(1) from the JPM, so a memory-based
# threshold is implementable.
#
# This sweep records ratio AND JLMU per (corpus, n) so the crossover can be
# read as a function of tree BYTES rather than key count.
set -e

. /var/tmp/jp113/o5p/bench-lock.sh
bench_lock_acquire "o5p-gate" "O5 reopen residency/threshold sweep" || exit 3
ROOT=/var/tmp/jp113
O5P=$ROOT/o5p
OUT=$O5P/o5p-residency.csv
MEMOUT=$O5P/o5p-residency-mem.csv

echo "corpus,n,jlmu_bytes" > $MEMOUT
for spec in wdense:262144 wdense:1000000 wdense:2000000 wdense:4000000 wdense:8000000 wdense:16000000 \
            wsparse:262144 wsparse:1000000 wsparse:4000000 wsparse:8000000 \
            wclust:262144 wclust:1000000 wclust:4000000 wclust:8000000 \
            wmix50:262144 wmix50:1000000 wmix50:4000000 wmix50:8000000; do
  c=${spec%%:*}; n=${spec#*:}
  taskset -c 2 $ROOT/bin/o5ppre_s1/o5pbench $c $n 1000 1 2>/dev/null \
    | awk -F, '/^MEM/{print $2","$3","$4}' >> $MEMOUT
done
echo "memory census done"

bash $O5P/o5p-bench.sh "pre,ctl,post,post0" "1,2,3,4,5" \
  "wdense:262144:2000000,wdense:1000000:2000000,wdense:2000000:2000000,wdense:4000000:2000000,wdense:8000000:2000000,wdense:16000000:2000000,wsparse:262144:2000000,wsparse:1000000:2000000,wsparse:4000000:2000000,wclust:1000000:2000000,wclust:4000000:2000000,wmix50:1000000:2000000,wmix50:4000000:2000000" \
  5 $OUT
echo RESIDENCY-DONE > $O5P/o5p-residency.done
