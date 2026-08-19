#!/bin/bash
# o5p-bench.sh <arms-csv> <buildseeds-csv> <corpusspec-csv> <trials> <outfile>
# corpusspec = name:n:reps ; one binary invocation per (trial, cell, arm,
# build) emits serial + serialold + mg256 + mg1024 rows. Trials interleave
# across arms so slow drift hits every arm equally; every run pinned to
# core 2. A GATE,...,FAIL line aborts the whole bench.
ROOT=/var/tmp/jp113
ARMS=$1; SEEDS=$2; SPECS=$3; TRIALS=$4; OUT=$5
echo "arm,seed,corpus,n,trial,kernel,ns_per_op,hits" > "$OUT"
echo "# loadavg_start=$(cut -d' ' -f1 /proc/loadavg)" >> "$OUT"
for t in $(seq 1 "$TRIALS"); do
  for spec in ${SPECS//,/ }; do
    c=${spec%%:*}; rest=${spec#*:}; n=${rest%%:*}; reps=${rest#*:}
    for arm in ${ARMS//,/ }; do
      for s in ${SEEDS//,/ }; do
        B=$ROOT/bin/o5p${arm}_s${s}/o5pbench
        [ -x "$B" ] || continue
        OUTP=$(taskset -c 2 "$B" "$c" "$n" "$reps" 1 "256,1024" 2>/dev/null)
        if echo "$OUTP" | grep -q '^GATE.*FAIL'; then
          echo "GATE FAIL arm=$arm s=$s cell=$spec" | tee -a "$OUT"; exit 1
        fi
        echo "$OUTP" | grep '^RES' | while IFS=, read -r _ cc nn rr kern ps ns hits; do
          echo "$arm,$s,$cc,$nn,$t,$kern,$ns,$hits" >> "$OUT"
        done
      done
    done
  done
  echo "# loadavg_trial${t}=$(cut -d' ' -f1 /proc/loadavg)" >> "$OUT"
done
echo "# loadavg_end=$(cut -d' ' -f1 /proc/loadavg)" >> "$OUT"
