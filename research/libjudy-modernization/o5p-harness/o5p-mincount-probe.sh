#!/bin/bash
# o5p-mincount-probe.sh -- what does the per-call composition analysis
# actually cost now that jl_mg_step is inlined again?
#
# The shipped default only analyzes batches of >= cJL_MULTIGET_PART_MIN_COUNT
# (2048) keys, so SHORT heterogeneous batches still run the archived
# unpartitioned pipeline -- which the original O5 gate measured losing
# x0.74 on mixed batches. That is a plausible-workload regression unless
# either (a) the analysis is cheap enough at small Count to lower the
# threshold, or (b) the caller declines the batched path below it.
#
# Arms: ctl (archived), post (MIN_COUNT=2048 default), postmc (MIN_COUNT=64
# -- analysis on every batch). Cells: unimodal (analysis is pure overhead)
# and mixed (analysis buys the partition) at block sizes 256/1024/4096.
set -e

. /var/tmp/jp113/o5p/o5p-lock.sh
bench_lock_acquire "o5p-gate" "O5 reopen PART_MIN_COUNT probe" || exit 3
ROOT=/var/tmp/jp113
O5P=$ROOT/o5p
OUT=$O5P/o5p-mincount.csv

bash $O5P/build-stock.sh $O5P/src-post/libjudy/src $O5P/arm-postmc \
  "-O2 -mpopcnt -DcJL_MULTIGET_PART_MIN_COUNT=64" > $O5P/postmc-build.log 2>&1
for s in 1 2 3 4 5; do
  mkdir -p $ROOT/bin/o5ppostmc_s${s}
  OBJS=$(ls $O5P/arm-postmc/obj/*.o | shuf --random-source=<(yes s$s))
  gcc -O2 -o $ROOT/bin/o5ppostmc_s${s}/o5pbench $O5P/o5pbench.c \
      -I$O5P/arm-postmc/include $OBJS -lm
done
echo "arm postmc built"

bash $O5P/o5p-bench.sh "pre,ctl,post,postmc" "1,2,3,4,5" \
  "wsparse:1000000:2000000,wdense:1000000:2000000,wclust:1000000:2000000,wmix50:1000000:2000000,wmix10:1000000:2000000,wmixs50:1000000:2000000" \
  7 $OUT
echo MINCOUNT-DONE > $O5P/o5p-mincount.done
