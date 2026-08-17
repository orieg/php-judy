/*
 * gate5_crash.c - Gate 5: what a reader sees when a writer dies mid-mutation.
 *
 * Gate 5 is mostly analysis, but two of its load-bearing claims are measurable
 * rather than assumable, so we measure them:
 *
 *   5a. Allocation burst per single JudyLIns. This bounds the arena leak when a
 *       writer is killed mid-insert: blocks already handed out but not yet
 *       linked into the tree are unreachable and unfreeable forever (there is
 *       no process teardown to reclaim a shared arena).
 *   5b. Kill writers at random points inside a bulk insert and have a separate
 *       reader process traverse the resulting shared tree. Record how often the
 *       tree is intact / silently wrong / fatal to the reader.
 *
 * Readers run in their own forked process precisely because a corrupt Judy tree
 * can fault the traversing process -- which is itself the finding.
 */

#include "shm_arena.h"

#include <Judy.h>

#include <signal.h>
#include <stdatomic.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#define ARENA_BYTES (512ull * 1024 * 1024)
#define TRIALS 40
#define PRELOAD 20000u

static Word_t mix(Word_t x) {
    x ^= x >> 33; x *= 0xff51afd7ed558ccdull;
    x ^= x >> 33; x *= 0xc4ceb9fe1a85ec53ull;
    x ^= x >> 33;
    return x;
}

typedef struct {
    Pvoid_t root;
    atomic_uint_least64_t inserted;   /* writer's progress counter */
    atomic_uint_least64_t in_flight;  /* 1 while inside a JLI call */
} shm_state_t;

/* ---- 5a: allocations per single insert ---- */
static unsigned g_cur_allocs, g_max_allocs;
static unsigned long g_cur_bytes, g_max_bytes;
static int g_counting;

static void audit(void *addr, size_t words, int is_free) {
    (void)addr;
    if (!g_counting || is_free) return;
    g_cur_allocs++;
    g_cur_bytes += words * sizeof(Word_t);
    if (g_cur_allocs > g_max_allocs) g_max_allocs = g_cur_allocs;
    if (g_cur_bytes > g_max_bytes) g_max_bytes = g_cur_bytes;
}

static void measure_alloc_burst(void) {
    arena_hdr_t *a = arena_create(ARENA_BYTES, ARENA_MODE_FREELIST);
    if (!a) { perror("arena_create"); exit(2); }
    arena_install(a);

    Pvoid_t L = NULL;
    Word_t *pv;
    unsigned long long hist[8] = { 0 };
    unsigned n = 300000;

    arena_set_audit(audit);
    g_counting = 1;
    for (unsigned i = 0; i < n; i++) {
        g_cur_allocs = 0; g_cur_bytes = 0;
        JLI(pv, L, mix(i));
        if (pv != PJERR && pv) *pv = i;
        unsigned b = g_cur_allocs < 7 ? g_cur_allocs : 7;
        hist[b]++;
    }
    g_counting = 0;
    arena_set_audit(NULL);

    printf("-- 5a: allocations issued by ONE JudyLIns (%u inserts) --\n", n);
    for (int i = 0; i < 8; i++) {
        if (!hist[i]) continue;
        printf("     %s%d alloc(s): %10llu  (%5.2f%%)\n",
               i == 7 ? ">=" : " ", i, hist[i], 100.0 * (double)hist[i] / n);
    }
    printf("     worst case: %u allocations, %lu bytes in a single insert\n",
           g_max_allocs, g_max_bytes);
    printf("     => a writer killed mid-insert strands up to %lu bytes in the\n"
           "        arena, unreachable and unfreeable (no teardown reclaims it).\n",
           g_max_bytes);

    int rc; JLFA(rc, L); (void)rc;
    arena_install(NULL);
    arena_destroy(a);
}

/* ---- 5b: kill a writer mid-mutation, then have a reader traverse ---- */

/* Returns: 0 = consistent, 1 = wrong count/order, 2 = crashed */
static int reader_verdict(shm_state_t *st, Word_t *out_count) {
    int fds[2];
    if (pipe(fds) != 0) return 2;

    pid_t r = fork();
    if (r == 0) {
        close(fds[0]);
        alarm(10); /* a corrupt tree can also send the reader into a long loop */
        Word_t *pv, idx = 0, count = 0, prev = 0;
        int order_ok = 1;
        JLF(pv, st->root, idx);
        while (pv) {
            if (count && idx <= prev) { order_ok = 0; break; }
            prev = idx; count++;
            if (count > 100000000ull) { order_ok = 0; break; } /* runaway */
            JLN(pv, st->root, idx);
        }
        ssize_t w = write(fds[1], &count, sizeof count);
        (void)w;
        close(fds[1]);
        _exit(order_ok ? 0 : 1);
    }
    close(fds[1]);
    Word_t cnt = 0;
    ssize_t got = read(fds[0], &cnt, sizeof cnt);
    close(fds[0]);
    int st_;
    waitpid(r, &st_, 0);
    *out_count = (got == (ssize_t)sizeof cnt) ? cnt : 0;

    if (WIFSIGNALED(st_)) return 2;
    if (WIFEXITED(st_) && WEXITSTATUS(st_) == 0) return 0;
    return 1;
}

static void crash_trials(void) {
    printf("\n-- 5b: writer SIGKILLed mid-insert, reader then traverses --\n");

    int intact = 0, inconsistent = 0, fatal = 0, lost_updates = 0;

    for (int t = 0; t < TRIALS; t++) {
        arena_hdr_t *a = arena_create(ARENA_BYTES, ARENA_MODE_FREELIST);
        if (!a) { perror("arena_create"); exit(2); }
        arena_install(a);

        shm_state_t *st = (shm_state_t *)((char *)a +
                          ((sizeof(arena_hdr_t) + 63) & ~63ull));
        a->bump = (uint64_t)((((char *)st - (char *)a) + sizeof(*st) + 63) & ~63ull);
        memset(st, 0, sizeof(*st));

        /* preload a stable tree */
        Word_t *pv;
        for (Word_t i = 0; i < PRELOAD; i++) {
            JLI(pv, st->root, mix(i));
            if (pv && pv != PJERR) *pv = i;
        }
        Word_t before;
        JLC(before, st->root, 0, -1);

        pid_t w = fork();
        if (w == 0) {
            /* writer: keep inserting until killed */
            for (Word_t i = PRELOAD; ; i++) {
                atomic_store(&st->in_flight, 1);
                Word_t *p;
                JLI(p, st->root, mix(i));
                if (p && p != PJERR) *p = i;
                atomic_store(&st->in_flight, 0);
                atomic_fetch_add(&st->inserted, 1);
            }
            _exit(0);
        }

        /* let the writer get going, then kill at a pseudo-random offset */
        struct timespec ts = { 0, (long)(200000 + (t * 137331) % 3000000) };
        nanosleep(&ts, NULL);
        kill(w, SIGKILL);
        int s; waitpid(w, &s, 0);

        unsigned long long done = atomic_load(&st->inserted);
        int was_in_flight = (int)atomic_load(&st->in_flight);

        Word_t seen = 0;
        int v = reader_verdict(st, &seen);

        if (v == 2) fatal++;
        else if (v == 1) inconsistent++;
        else {
            intact++;
            /* even "intact" can silently lose the in-flight insert */
            if (seen != before + done) lost_updates++;
        }

        if (t < 5 || v != 0) {
            printf("     trial %2d: killed after %llu inserts (in_flight=%d) -> "
                   "%s, reader saw %lu entries (expected >= %lu)\n",
                   t, done, was_in_flight,
                   v == 0 ? "traversable" : v == 1 ? "INCONSISTENT" : "READER CRASHED",
                   (unsigned long)seen, (unsigned long)(before + done));
        }

        arena_install(NULL);
        arena_destroy(a);
    }

    printf("\n     over %d kill trials:\n", TRIALS);
    printf("       traversable          : %d\n", intact);
    printf("       inconsistent/ordering: %d\n", inconsistent);
    printf("       reader crashed/hung  : %d\n", fatal);
    printf("       traversable but with a lost/partial update: %d\n", lost_updates);
}

/* ---- 5c: cost of the "poison the segment and rebuild" recovery ---- */
static double now_s(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (double)ts.tv_sec + (double)ts.tv_nsec / 1e9;
}

static int cmp_d(const void *a, const void *b) {
    double x = *(const double *)a, y = *(const double *)b;
    return (x > y) - (x < y);
}

static void measure_rebuild_cost(void) {
    printf("\n-- 5c: cost of discarding a corrupt segment and rebuilding --\n");
    printf("   (this is the recovery cost of the accept-corruption strategy;\n"
           "    a cache is reconstructible, so 'nuke and refill' is legal)\n");

    const unsigned sizes[] = { 100000, 1000000 };
    for (size_t si = 0; si < sizeof sizes / sizeof sizes[0]; si++) {
        unsigned n = sizes[si];
        double r[5];
        for (int k = 0; k < 5; k++) {
            arena_hdr_t *a = arena_create(ARENA_BYTES, ARENA_MODE_FREELIST);
            if (!a) { perror("arena_create"); exit(2); }
            arena_install(a);
            Pvoid_t L = NULL;
            Word_t *pv;
            double t0 = now_s();
            for (unsigned i = 0; i < n; i++) {
                JLI(pv, L, mix(i));
                if (pv && pv != PJERR) *pv = i;
            }
            r[k] = now_s() - t0;
            arena_install(NULL);
            arena_destroy(a);
        }
        qsort(r, 5, sizeof(double), cmp_d);
        printf("     rebuild %8u keys: min %.3fs  median %.3fs  max %.3fs\n",
               n, r[0], r[2], r[4]);
    }
    printf("     NOTE: measured on a LOADED machine; treat as an upper bound.\n");
}

int main(void) {
    printf("== Gate 5: crash consistency (writer death) ==\n\n");
    measure_alloc_burst();
    crash_trials();
    measure_rebuild_cost();
    printf("\nGATE 5: see FINDINGS.md\n");
    return 0;
}
