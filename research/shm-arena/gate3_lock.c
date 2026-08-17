/*
 * gate3_lock.c - Gate 3: process-shared locking for the arena.
 *
 * Four questions:
 *   3a. Which primitives actually exist here? pthread_rwlock pshared,
 *       pthread_mutex pshared, and crucially ROBUST mutexes (Linux-only).
 *   3b. Per-op overhead, uncontended, for: no lock / pshared rwlock rdlock /
 *       pshared mutex / seqlock optimistic read.
 *   3c. Same under N-process contention on a read-dominated workload, which is
 *       what a cache actually does.
 *   3d. Kill a lock holder mid-critical-section. What do the survivors see?
 *
 * Timing methodology: each configuration is run REPS times; we report
 * median and min/max across reps, never a single run.
 */

#include "shm_arena.h"

#include <Judy.h>

#include <errno.h>
#include <pthread.h>
#include <signal.h>
#include <stdatomic.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#define ARENA_BYTES (128ull * 1024 * 1024)
#define N_KEYS 200000u
#define N_OPS 2000000u
#define REPS 15

/*
 * Estimator note: on a loaded machine the MEDIAN of wall-clock per-op timings
 * is badly biased upward by scheduler interference (and can even invert the
 * ordering of two configurations). Interference can only ADD time, never remove
 * it, so the MINIMUM across repetitions is the robust estimator for per-op cost
 * under contamination. We report min as primary and median/max as spread.
 */

/*
 * Robust-mutex detection.
 *
 * NOTE: glibc declares PTHREAD_MUTEX_ROBUST as an ENUM CONSTANT, not a macro,
 * so "#if defined(PTHREAD_MUTEX_ROBUST)" is FALSE on Linux even though robust
 * mutexes are fully supported. Detecting by feature-macro is the trap here;
 * detect by C library instead.
 *   glibc  : pthread_mutexattr_setrobust + pthread_mutex_consistent  -> yes
 *   macOS  : symbols do not exist at all                             -> no
 */
#if defined(__GLIBC__)
#  define HAVE_ROBUST_MUTEX 1
#else
#  define HAVE_ROBUST_MUTEX 0
#endif

static Word_t mix(Word_t x) {
    x ^= x >> 33; x *= 0xff51afd7ed558ccdull;
    x ^= x >> 33; x *= 0xc4ceb9fe1a85ec53ull;
    x ^= x >> 33;
    return x;
}

static double now_ns(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (double)ts.tv_sec * 1e9 + (double)ts.tv_nsec;
}

static int cmp_dbl(const void *a, const void *b) {
    double x = *(const double *)a, y = *(const double *)b;
    return (x > y) - (x < y);
}

/* shared control block, lives in the arena */
typedef struct {
    Pvoid_t root;
    pthread_rwlock_t rw;
    pthread_mutex_t  mtx;
    atomic_uint_least64_t seq;    /* seqlock counter: odd = write in progress */
    atomic_int start_flag;
    atomic_int holder_alive;
    atomic_long total_ops;
} ctl_t;

/* ------------------------------------------------------------------ */
/* 3a: capability probe                                                */
/* ------------------------------------------------------------------ */

static int have_robust = 0;

static void probe_capabilities(void) {
    printf("-- 3a: primitive availability on this platform --\n");

    pthread_rwlockattr_t ra;
    int rc = pthread_rwlockattr_init(&ra);
    rc |= pthread_rwlockattr_setpshared(&ra, PTHREAD_PROCESS_SHARED);
    printf("   pthread_rwlockattr_setpshared(PROCESS_SHARED) : %s\n",
           rc == 0 ? "OK" : strerror(rc));
    pthread_rwlockattr_destroy(&ra);

    pthread_mutexattr_t ma;
    rc = pthread_mutexattr_init(&ma);
    rc |= pthread_mutexattr_setpshared(&ma, PTHREAD_PROCESS_SHARED);
    printf("   pthread_mutexattr_setpshared(PROCESS_SHARED)  : %s\n",
           rc == 0 ? "OK" : strerror(rc));

#if HAVE_ROBUST_MUTEX
    {
        int r2 = pthread_mutexattr_setrobust(&ma, PTHREAD_MUTEX_ROBUST);
        printf("   pthread_mutexattr_setrobust(ROBUST)           : %s\n",
               r2 == 0 ? "OK  <-- EOWNERDEAD recovery available" : strerror(r2));
        have_robust = (r2 == 0);
    }
#else
    printf("   pthread_mutexattr_setrobust(ROBUST)           : "
           "ABSENT ON THIS PLATFORM  <-- no EOWNERDEAD recovery\n");
    have_robust = 0;
#endif
    pthread_mutexattr_destroy(&ma);

    /* robust RWLOCKS do not exist in POSIX on any platform */
    printf("   robust *rwlock* (any platform)                : "
           "DOES NOT EXIST IN POSIX\n");
    printf("   -> a robust design can only use a robust MUTEX, not a rwlock.\n");
}

/* ------------------------------------------------------------------ */
/* lock modes                                                          */
/* ------------------------------------------------------------------ */

typedef enum { M_NOLOCK, M_RWLOCK, M_MUTEX, M_SEQLOCK } mode_t_;
static const char *mode_name(mode_t_ m) {
    switch (m) {
        case M_NOLOCK:  return "no lock (unsafe baseline)";
        case M_RWLOCK:  return "pshared rwlock rdlock";
        case M_MUTEX:   return "pshared mutex lock";
        case M_SEQLOCK: return "seqlock optimistic read";
    }
    return "?";
}

/* one read op: lookup a key under the given lock discipline */
static inline int do_read(ctl_t *c, mode_t_ m, Word_t key, Word_t *out) {
    Word_t *pv;
    switch (m) {
        case M_NOLOCK:
            JLG(pv, c->root, key);
            *out = pv ? *pv : 0;
            return 1;
        case M_RWLOCK:
            pthread_rwlock_rdlock(&c->rw);
            JLG(pv, c->root, key);
            *out = pv ? *pv : 0;
            pthread_rwlock_unlock(&c->rw);
            return 1;
        case M_MUTEX:
            pthread_mutex_lock(&c->mtx);
            JLG(pv, c->root, key);
            *out = pv ? *pv : 0;
            pthread_mutex_unlock(&c->mtx);
            return 1;
        case M_SEQLOCK: {
            uint64_t s0, s1;
            int tries = 0;
            do {
                s0 = atomic_load_explicit(&c->seq, memory_order_acquire);
                if (s0 & 1u) { continue; }
                JLG(pv, c->root, key);
                *out = pv ? *pv : 0;
                atomic_thread_fence(memory_order_acquire);
                s1 = atomic_load_explicit(&c->seq, memory_order_relaxed);
            } while ((s0 != s1 || (s0 & 1u)) && ++tries < 1000);
            return 1;
        }
    }
    return 0;
}

/* ------------------------------------------------------------------ */
/* 3b: uncontended per-op cost                                         */
/* ------------------------------------------------------------------ */

static volatile Word_t g_sink; /* keeps the benchmark loop from being elided */

static double bench_uncontended(ctl_t *c, mode_t_ m, unsigned nops) {
    Word_t sink = 0, out = 0;
    double t0 = now_ns();
    for (unsigned i = 0; i < nops; i++) {
        do_read(c, m, mix(i % N_KEYS), &out);
        sink += out;
    }
    double t1 = now_ns();
    g_sink = sink;
    return (t1 - t0) / nops;
}

/* ------------------------------------------------------------------ */
/* 3c: N-process contention, read-dominated                            */
/* ------------------------------------------------------------------ */

static double bench_contended(ctl_t *c, mode_t_ m, int nproc, unsigned nops,
                              int writer_permille) {
    atomic_store(&c->start_flag, 0);
    atomic_store(&c->total_ops, 0);

    for (int i = 0; i < nproc; i++) {
        pid_t p = fork();
        if (p == 0) {
            while (atomic_load(&c->start_flag) == 0) { /* spin to start together */ }
            Word_t out = 0, sink = 0;
            unsigned seed = (unsigned)(i * 7919 + 13);
            for (unsigned k = 0; k < nops; k++) {
                seed = seed * 1103515245u + 12345u;
                int is_write = ((seed >> 16) % 1000) < (unsigned)writer_permille;
                if (is_write && m != M_NOLOCK) {
                    /* exclusive section */
                    if (m == M_RWLOCK) pthread_rwlock_wrlock(&c->rw);
                    else if (m == M_MUTEX) pthread_mutex_lock(&c->mtx);
                    else atomic_fetch_add(&c->seq, 1); /* seqlock: make odd */

                    Word_t *pv;
                    Word_t key = mix(seed % N_KEYS);
                    JLG(pv, c->root, key);
                    if (pv) *pv = *pv + 1;

                    if (m == M_RWLOCK) pthread_rwlock_unlock(&c->rw);
                    else if (m == M_MUTEX) pthread_mutex_unlock(&c->mtx);
                    else atomic_fetch_add(&c->seq, 1); /* back to even */
                } else {
                    do_read(c, m, mix(seed % N_KEYS), &out);
                    sink += out;
                }
            }
            atomic_fetch_add(&c->total_ops, (long)nops);
            _exit(sink == 0xdeadbeef ? 1 : 0);
        }
    }

    double t0 = now_ns();
    atomic_store(&c->start_flag, 1);
    for (int i = 0; i < nproc; i++) { int s; wait(&s); }
    double t1 = now_ns();

    long total = atomic_load(&c->total_ops);
    return (t1 - t0) / (double)total; /* ns per op, aggregate throughput */
}

/* ------------------------------------------------------------------ */
/* 3d: kill a lock holder mid-critical-section                         */
/* ------------------------------------------------------------------ */

static void test_holder_death(ctl_t *c) {
    printf("-- 3d: lock holder killed inside the critical section --\n");

    atomic_store(&c->holder_alive, 0);

    pid_t victim = fork();
    if (victim == 0) {
        /* take the write lock, announce, then die while still holding it */
        pthread_mutex_lock(&c->mtx);
        atomic_store(&c->holder_alive, 1);
        for (;;) pause();     /* never unlocks */
        _exit(0);
    }

    while (atomic_load(&c->holder_alive) == 0) { /* wait until lock is held */ }
    usleep(50000);
    kill(victim, SIGKILL);
    int st; waitpid(victim, &st, 0);
    printf("   victim killed with SIGKILL while holding the pshared mutex\n");

    /* survivor tries to acquire it (blocking, with an alarm so we cannot hang) */
    pid_t survivor = fork();
    if (survivor == 0) {
        alarm(3);
        int rc = pthread_mutex_lock(&c->mtx);
        if (rc == EOWNERDEAD) {
#if HAVE_ROBUST_MUTEX
            /* robust path: the lock IS acquired, but the data it protected is
             * in an unknown state. Declaring it consistent is a promise the
             * application must actually keep -- see gate 5. */
            int rc2 = pthread_mutex_consistent(&c->mtx);
            pthread_mutex_unlock(&c->mtx);
            _exit(rc2 == 0 ? 2 : 3);
#else
            _exit(2);
#endif
        }
        if (rc == 0) { pthread_mutex_unlock(&c->mtx); _exit(0); }
        _exit(1);
    }
    waitpid(survivor, &st, 0);

    if (WIFSIGNALED(st) && WTERMSIG(st) == SIGALRM) {
        printf("   survivor pthread_mutex_lock() -> HUNG FOREVER "
               "(killed by alarm after 3s)\n");
        printf("   ==> the whole pool is permanently deadlocked by one dead worker\n");
    } else {
        int code = WIFEXITED(st) ? WEXITSTATUS(st) : -1;
        switch (code) {
            case 0:
                printf("   survivor pthread_mutex_lock() -> ACQUIRED "
                       "(lock auto-released on death)\n");
                break;
            case 2:
                printf("   survivor pthread_mutex_lock() -> EOWNERDEAD, "
                       "pthread_mutex_consistent() OK\n");
                printf("   ==> LOCK recovers. The DATA it guarded does not "
                       "(see gate 5).\n");
                break;
            case 3:
                printf("   survivor got EOWNERDEAD but "
                       "pthread_mutex_consistent() FAILED\n");
                break;
            default:
                printf("   survivor pthread_mutex_lock() -> failed (code %d)\n", code);
        }
    }

    /* flock(2) comparison: kernel releases on process death, unconditionally */
    printf("   [reference] flock/fcntl advisory locks are released by the kernel\n"
           "               on process death on BOTH platforms -- the portable\n"
           "               fallback when robust mutexes are unavailable.\n");
}

/* ------------------------------------------------------------------ */

int main(void) {
    printf("== Gate 3: process-shared locking ==\n");
    probe_capabilities();

    arena_hdr_t *a = arena_create(ARENA_BYTES, ARENA_MODE_FREELIST);
    if (!a) { perror("arena_create"); return 2; }
    arena_install(a);

    ctl_t *c = (ctl_t *)((char *)a + ((sizeof(arena_hdr_t) + 63) & ~63ull));
    a->bump = (uint64_t)((((char *)c - (char *)a) + sizeof(ctl_t) + 63) & ~63ull);
    memset(c, 0, sizeof(*c));

    pthread_rwlockattr_t ra;
    pthread_rwlockattr_init(&ra);
    pthread_rwlockattr_setpshared(&ra, PTHREAD_PROCESS_SHARED);
    if (pthread_rwlock_init(&c->rw, &ra) != 0) { perror("rwlock_init"); return 2; }

    pthread_mutexattr_t ma;
    pthread_mutexattr_init(&ma);
    pthread_mutexattr_setpshared(&ma, PTHREAD_PROCESS_SHARED);
#if HAVE_ROBUST_MUTEX
    pthread_mutexattr_setrobust(&ma, PTHREAD_MUTEX_ROBUST);
#endif
    if (pthread_mutex_init(&c->mtx, &ma) != 0) { perror("mutex_init"); return 2; }

    /* build the tree */
    Word_t *pv;
    for (Word_t i = 0; i < N_KEYS; i++) {
        Word_t k = mix(i);
        JLI(pv, c->root, k);
        *pv = k;
    }
    printf("\n   tree: %u keys, arena footprint %.2f MB\n\n",
           N_KEYS, (double)a->bump / (1024 * 1024));

    /* ---- 3b ---- */
    printf("-- 3b: uncontended per-op cost, single process (%u ops x %d reps) --\n",
           N_OPS / 10, REPS);
    printf("   %-28s %10s %10s %10s %14s\n", "mode", "MIN", "median", "max",
           "lock cost");
    mode_t_ modes[] = { M_NOLOCK, M_RWLOCK, M_MUTEX, M_SEQLOCK };
    double base_min = 0;
    for (size_t mi = 0; mi < sizeof modes / sizeof modes[0]; mi++) {
        double r[REPS];
        for (int k = 0; k < REPS; k++) r[k] = bench_uncontended(c, modes[mi], N_OPS / 10);
        qsort(r, REPS, sizeof(double), cmp_dbl);
        if (mi == 0) base_min = r[0];
        printf("   %-28s %8.2fns %8.2fns %8.2fns %+10.2fns\n",
               mode_name(modes[mi]), r[0], r[REPS / 2], r[REPS - 1],
               r[0] - base_min);
    }

    /* ---- 3c ---- */
    printf("\n-- 3c: N-process contention, read-dominated (99%% read / 1%% write) --\n");
    printf("   best-of-5 aggregate ns per op (lower = better throughput)\n");
    int procs[] = { 1, 2, 4, 8 };
    printf("   %-28s", "mode");
    for (size_t pi = 0; pi < sizeof procs / sizeof procs[0]; pi++)
        printf(" %8dp", procs[pi]);
    printf("\n");
    for (size_t mi = 0; mi < sizeof modes / sizeof modes[0]; mi++) {
        printf("   %-28s", mode_name(modes[mi]));
        for (size_t pi = 0; pi < sizeof procs / sizeof procs[0]; pi++) {
            double r[5];
            for (int k = 0; k < 5; k++)
                r[k] = bench_contended(c, modes[mi], procs[pi], 200000, 10);
            qsort(r, 5, sizeof(double), cmp_dbl);
            printf(" %7.1fns", r[0]); /* min: robust under interference */
        }
        printf("\n");
    }

    /* ---- 3d ---- */
    printf("\n");
    test_holder_death(c);

    printf("\nGATE 3: see FINDINGS.md (verdict is platform-dependent; "
           "robust=%s here)\n", have_robust ? "YES" : "NO");
    arena_install(NULL);
    return 0;
}
