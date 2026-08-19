#!/bin/bash
# o5p-mkarm.sh <arm> [extra-cflags] -- build one O5P bench arm from
# /var/tmp/jp113/o5p/src-<arm>/libjudy/src using build-stock.sh (CFLAGS
# "-O2 -mpopcnt", matching the production vendored build), then link FIVE
# o5pbench binaries per arm with seeded random object order (link-order
# randomization is the per-build replication axis, as in O1/O3/O4/O5).
#
# Arms: pre  (origin/main -- no JudyLMultiGet)
#       ctl  (vendor/stage3-o5-amac -- archived UNPARTITIONED multiget)
#       post (vendor/stage3-o5-partition -- partitioned multiget)
#       post0 (post src, serial thresholds compiled out -- crossover)
# NOTE: a ctl built from the same objects as post would be byte-identical
# only if sources matched; here ctl is a DIFFERENT source tree (the
# archived impl). The layout control is the 5 randomized-link builds
# within each arm; temporal noise is calibrated by interleaved trials.
set -e
ROOT=/var/tmp/jp113
O5P=$ROOT/o5p
ARM=$1
EXTRA=${2:-}
case $ARM in post0) SRCARM=post ;; *) SRCARM=$ARM ;; esac
SRC=$O5P/src-$SRCARM/libjudy/src
[ -d "$SRC" ] || { echo "no src at $SRC" >&2; exit 2; }

bash $O5P/build-stock.sh "$SRC" "$O5P/arm-$ARM" "-O2 -mpopcnt $EXTRA" > $O5P/$ARM-build.log 2>&1

# feature check on the built objects (stale-build hazard): the multiget TU
# must exist exactly on ctl/post/post0 arms.
if ls $O5P/arm-$ARM/obj/L_JudyMultiGet.o >/dev/null 2>&1; then MG=present; else MG=absent; fi
echo "featurecheck arm=$ARM L_JudyMultiGet.o=$MG"
case $ARM in
  ctl|post|post0) [ $MG = present ] || { echo "ARM $ARM MISSING MULTIGET" >&2; exit 3; } ;;
  *)              [ $MG = absent ]  || { echo "ARM $ARM UNEXPECTED MULTIGET" >&2; exit 3; } ;;
esac
# partition-presence check (ctl vs post must differ): the counting
# partition's scatter loop exists only in the partitioned TU. Grep the
# arm's source (the objects are built from it by this same script run).
PARTSRC=$(grep -c "counting partition" $SRC/JudyCommon/JudyMultiGet.c 2>/dev/null || true)
echo "featurecheck arm=$ARM partition-src-refs=$PARTSRC"
case $ARM in
  post|post0) [ "$PARTSRC" -ge 1 ] || { echo "ARM $ARM SRC LACKS PARTITION" >&2; exit 3; } ;;
  ctl)        [ "$PARTSRC" = 0 ]   || { echo "ARM ctl SRC UNEXPECTEDLY PARTITIONED" >&2; exit 3; } ;;
esac

for s in 1 2 3 4 5; do
  mkdir -p $ROOT/bin/o5p${ARM}_s${s}
  OBJS=$(ls $O5P/arm-$ARM/obj/*.o | shuf --random-source=<(yes s$s))
  gcc -O2 -o $ROOT/bin/o5p${ARM}_s${s}/o5pbench $O5P/o5pbench.c \
      -I$O5P/arm-$ARM/include $OBJS -lm
  R=$(taskset -c 2 $ROOT/bin/o5p${ARM}_s${s}/o5pbench wdense 1000 1000 1 | grep '^FEAT' | cut -d, -f4)
  [ "$R" = "$MG" ] || { echo "ARM $ARM s$s FEATURE MISMATCH ($R vs $MG)" >&2; exit 3; }
done
echo "arm $ARM done"
