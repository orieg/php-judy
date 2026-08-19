#!/bin/bash
# php4-driver.sh -- O5 REOPEN PHP-level A/B inside php-judy-bench image.
# Arms: pre (origin/main serial getAll), ctl (archived unpartitioned
# multiget), post (partitioned multiget + 4096-key gather).
# Replication unit = PROCESS RUN (disclosed: weaker than the C gate's
# build replication; the C gate carries the layout-noise-proofed claim).
# EVERY raw invocation output is persisted under raw/ (the original drop
# lost its "+10% sparse" cell to an unpersisted run).
set -e

. /var/tmp/jp113/o5p/o5p-lock.sh
bench_lock_acquire "o5p-gate" "O5 reopen PHP-level A/B" || exit 3
P=/var/tmp/jp113/o5p/php
OUT=$P/php4-bench.csv
mkdir -p $P/raw
cd $P

build() { # $1 = arm
  docker run --rm -v $P/$1:/usr/src/php-judy -w /usr/src/php-judy php-judy-bench:latest \
    sh -c 'find . -name "*.lo" -delete; find . -name "*.o" -delete; rm -f Makefile config.status; phpize >/dev/null && ./configure >/dev/null && make -j8 >/dev/null 2>&1 && echo built' \
    > $P/$1-build.log 2>&1
  grep -q built $P/$1-build.log || { echo "BUILD FAILED $1"; tail $P/$1-build.log; exit 1; }
}

for arm in pre ctl post; do
  [ -f $P/$arm/modules/judy.so ] || build $arm
done

# feature checks: symbol present only in ctl/post; the loaded module is
# the mounted one (stale-baked-in-extension hazard: clear PHP_INI_SCAN_DIR
# and verify via /proc/self/maps).
for arm in pre ctl post; do
  SYM=$(docker run --rm -v $P:/p php-judy-bench:latest sh -c "nm -D /p/$arm/modules/judy.so 2>/dev/null | grep -c JudyLMultiGet || true")
  MAP=$(docker run --rm -v $P:/p php-judy-bench:latest sh -c "PHP_INI_SCAN_DIR= php -d extension=/p/$arm/modules/judy.so -r 'echo (int)(strpos(file_get_contents(\"/proc/self/maps\"), \"/p/$arm/modules/judy.so\") !== false);'")
  echo "featurecheck arm=$arm JudyLMultiGet-exports=$SYM mapped-from-mount=$MAP" | tee -a $P/php4-feature.log
  [ "$MAP" = "1" ] || { echo "ARM $arm NOT MAPPED FROM MOUNT"; exit 1; }
  case $arm in
    pre)  [ "$SYM" = "0" ] || { echo "pre .so unexpectedly has JudyLMultiGet"; exit 1; } ;;
    *)    [ "$SYM" != "0" ] || { echo "$arm .so lacks JudyLMultiGet"; exit 1; } ;;
  esac
done

echo "arm,trial,op,shape,n,median_ms,cks" > $OUT
echo "# loadavg_start=$(cut -d' ' -f1 /proc/loadavg)" >> $OUT
for t in 1 2 3 4 5 6 7; do
  for spec in getall:sparse:1000000:9 getall:mix:1000000:9 getall:dense:1000000:9 getall:dense90:1000000:9 getall:sparse:8000000:7 getall:mix:8000000:7 getall:dense:8000000:7 getall:dense90:8000000:7 getall:clust:1000000:9 getall:clust:8000000:7 foreach:clust:1000000:9 foreach:sparse:1000000:9 foreach:mix:1000000:9 foreach:dense:1000000:9 foreach:sparse:8000000:7; do
    op=${spec%%:*}; r1=${spec#*:}; shape=${r1%%:*}; r2=${r1#*:}; n=${r2%%:*}; reps=${r2#*:}
    for arm in pre ctl post; do
      RAW=$P/raw/t${t}-${arm}-${op}-${shape}-${n}.out
      docker run --rm --cpuset-cpus=2 -v $P:/p php-judy-bench:latest \
        sh -c "PHP_INI_SCAN_DIR= php -d memory_limit=-1 -d extension=/p/$arm/modules/judy.so /p/phpbench4.php $op $shape $n $reps" > $RAW 2>&1
      L=$(grep PHPRES4 $RAW)
      IFS=, read -r _ oo ss nn med cks <<< "$L"
      echo "$arm,$t,$oo,$ss,$nn,$med,$cks" >> $OUT
    done
  done
  echo "# loadavg_t${t}=$(cut -d' ' -f1 /proc/loadavg)" >> $OUT
done
echo "# loadavg_end=$(cut -d' ' -f1 /proc/loadavg)" >> $OUT
echo DONE > $P/php4-bench.done
