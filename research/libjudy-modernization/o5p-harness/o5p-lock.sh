#!/bin/bash
# o5p-lock.sh -- honeycomb exclusive-benchmark lock.
#
# WHY THIS EXISTS (2026-08-19): two php-judy benchmark campaigns ran
# concurrently on honeycomb (this O5-reopen gate matrix pinned to core 2,
# and a three-arm system-vs-bundled campaign in docker). BOTH individually
# satisfied the project's loadavg < N/2 hygiene check -- 24 cores, loadavg
# never exceeded 2.9 -- and both were corrupted anyway: an untouched
# BASELINE arm (wsparse n=8e6, pre/serial) moved 69.4-69.8 -> 147-172
# ns/op between trials. Two memory-bound benchmarks contend for LLC and
# memory bandwidth no matter how their cores are pinned, so loadavg is
# NOT a sufficient guard on a 24-core box. Mutual exclusion is.
#
# usage:  . o5p-lock.sh; bench_lock_acquire "<agent>" "<what>"; ... ; bench_lock_release
LOCK=/var/tmp/BENCH_LOCK

bench_lock_acquire() {
    local who=$1 what=$2
    if [ -e "$LOCK" ]; then
        echo "BENCH_LOCK held -- refusing to start. Holder:" >&2
        sed 's/^/  /' "$LOCK" >&2
        echo "If the holder is dead, remove $LOCK by hand after confirming." >&2
        return 3
    fi
    # O_EXCL create: loses safely if another job wins the race.
    if ! (set -o noclobber; printf "agent=%s\npid=%s\nhost_pid=%s\nstarted=%s\nwhat=%s\n" \
            "$who" "$$" "$(hostname)" "$(date -u +%FT%TZ)" "$what" > "$LOCK") 2>/dev/null; then
        echo "BENCH_LOCK race lost -- another job took it. Not starting." >&2
        return 3
    fi
    trap 'bench_lock_release' EXIT INT TERM
    echo "BENCH_LOCK acquired by $who: $what"
}

bench_lock_release() {
    rm -f "$LOCK"
}
