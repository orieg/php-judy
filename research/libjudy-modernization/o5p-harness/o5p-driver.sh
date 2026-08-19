#!/bin/bash
# o5p-driver.sh -- full O5 REOPEN gate: env snapshots, memory parity,
# L3-scale sweep (unimodal no-regression cells), heterogeneous-mix sweep
# (the reopen's decisive cells incl. strided/clustered/32-bit dense
# variants), crossover (threshold re-derivation) sweep, out-of-cache
# cells. Writes o5p-bench.done when finished (coordinator watch marker).
set -e

. /var/tmp/jp113/o5p/bench-lock.sh
bench_lock_acquire "o5p-gate" "O5 reopen gate matrix (l3/mix/big/xover)" || exit 3
ROOT=/var/tmp/jp113
O5P=$ROOT/o5p
cd $O5P
ENV=$O5P/o5p-bench-env.txt
echo "# pre-bench ps snapshot: $(date -u +%FT%TZ)" > $ENV
ps -A -o %cpu,%mem,comm --sort=-%cpu | head -6 >> $ENV
cat /proc/loadavg >> $ENV

# memory parity: JLMU byte-identical across all arms on every corpus
for c in wdense wsparse wbase16 wbase64 wclust wrand40 wleaf1 wimm3 wpair wmix50 wmixs50 wmixc50 wmix32_50; do
  for arm in pre ctl post post0; do
    taskset -c 2 $ROOT/bin/o5p${arm}_s1/o5pbench $c 1000000 1000 1 2>/dev/null \
      | grep '^MEM' > $O5P/mem-$arm-$c.txt
  done
  for arm in ctl post post0; do
    diff -q $O5P/mem-pre-$c.txt $O5P/mem-$arm-$c.txt >/dev/null \
      || { echo "MEMPARITY MISMATCH $c $arm" | tee -a $ENV; exit 1; }
  done
done
echo "MEMPARITY: pre/ctl/post/post0 byte-identical on 13 corpora" | tee -a $ENV

# L3-resident unimodal cells (no-regression gate vs ctl)
bash $O5P/o5p-bench.sh "pre,ctl,post,post0" "1,2,3,4,5" \
  "wdense:1000000:2000000,wsparse:1000000:2000000,wbase16:1048576:2000000,wbase64:1000000:2000000,wclust:1000000:2000000,wrand40:1000000:2000000,wleaf1:1000000:2000000,wimm3:1000000:2000000,wpair:1000000:2000000" \
  7 $O5P/o5p-bench-l3.csv
echo "# after-l3: $(date -u +%FT%TZ)" >> $ENV; cat /proc/loadavg >> $ENV

# heterogeneous-mix cells (the reopen's decisive family)
bash $O5P/o5p-bench.sh "pre,ctl,post,post0" "1,2,3,4,5" \
  "wmix10:1000000:2000000,wmix25:1000000:2000000,wmix50:1000000:2000000,wmix75:1000000:2000000,wmix90:1000000:2000000,wmixs50:1000000:2000000,wmixc50:1000000:2000000,wmix32_50:1000000:2000000,wmix32_90:1000000:2000000" \
  7 $O5P/o5p-bench-mix.csv
echo "# after-mix: $(date -u +%FT%TZ)" >> $ENV; cat /proc/loadavg >> $ENV

# out-of-cache cells
bash $O5P/o5p-bench.sh "pre,ctl,post,post0" "1,2,3,4,5" \
  "wsparse:8000000:2000000,wdense:8000000:2000000,wmix50:8000000:2000000" \
  5 $O5P/o5p-bench-big.csv
echo "# after-big: $(date -u +%FT%TZ)" >> $ENV; cat /proc/loadavg >> $ENV

# crossover sweep: raw pipelined+partitioned (post0) vs serial across
# populations; shipped default (post) must be ~null below its threshold
bash $O5P/o5p-bench.sh "pre,ctl,post,post0" "1,2,3,4,5" \
  "wdense:1024:2000000,wdense:4096:2000000,wdense:16384:2000000,wdense:65536:2000000,wdense:262144:2000000,wsparse:1024:2000000,wsparse:4096:2000000,wsparse:16384:2000000,wsparse:65536:2000000,wsparse:262144:2000000,wclust:16384:2000000,wclust:65536:2000000,wbl:6561:2000000,wmix50:16384:2000000,wmix50:65536:2000000,wmix50:262144:2000000" \
  5 $O5P/o5p-bench-xover.csv
echo "# post-bench: $(date -u +%FT%TZ)" >> $ENV; cat /proc/loadavg >> $ENV
ps -A -o %cpu,%mem,comm --sort=-%cpu | head -6 >> $ENV
# Baseline-stability guard: the `pre` arm is untouched, so a per-trial
# drift in it means the MACHINE changed, not the code. loadavg alone did
# not catch the 2026-08-19 collision; this does.
STAB=0
python3 $O5P/bench-stability.py $O5P/o5p-bench-l3.csv $O5P/o5p-bench-mix.csv \
    $O5P/o5p-bench-big.csv $O5P/o5p-bench-xover.csv > $O5P/o5p-stability.txt 2>&1 || STAB=1
if [ $STAB -ne 0 ]; then
  echo "BASELINE-STABILITY GUARD FAILED -- see o5p-stability.txt" | tee -a $ENV
  echo BENCH-CONTAMINATED > $O5P/o5p-bench.done
  exit 1
fi
echo BENCH-DONE > $O5P/o5p-bench.done
