/*
 * gate4_profile.c - Gate 4: arena management / fragmentation at 1M-key scale.
 *
 * Measures the REAL Judy allocation pattern rather than assuming one:
 *   4a. size-class histogram + alloc/free churn ratio, for dense/sequential and
 *       sparse/random key distributions (they build very different node types).
 *   4b. bump-only arena vs size-class-freelist arena under TTL-style delete
 *       churn: peak mapped bytes vs live bytes = the fragmentation bound.
 *   4c. steady-state churn (the judy-cache case: insert with expiry, so the
 *       live set stays flat while allocations keep flowing).
 *
 * The number that decides the gate is 4b/4c: a bump-only arena that never
 * reuses freed blocks grows without bound under delete churn.
 */

#include "shm_arena.h"

#include <Judy.h>

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define ARENA_BYTES (3072ull * 1024 * 1024)
#define N_KEYS 1000000u

static Word_t mix(Word_t x) {
    x ^= x >> 33; x *= 0xff51afd7ed558ccdull;
    x ^= x >> 33; x *= 0xc4ceb9fe1a85ec53ull;
    x ^= x >> 33;
    return x;
}

static Word_t keygen(unsigned i, int sparse) {
    return sparse ? mix(i) : (Word_t)i;
}

/* ------------------------------------------------------------------ */
/* 4a: size-class histogram                                            */
/* ------------------------------------------------------------------ */

static void report_histogram(arena_hdr_t *a, const char *label) {
    printf("\n   size-class histogram [%s] (words -> allocs, %% of total):\n", label);
    uint64_t total = 0;
    for (unsigned w = 0; w <= ARENA_MAX_WORDS; w++) total += a->alloc_calls[w];
    if (!total) { printf("     (none)\n"); return; }

    /* print classes accounting for >=0.5% of allocations, plus the largest */
    unsigned shown = 0;
    for (unsigned w = 0; w <= ARENA_MAX_WORDS; w++) {
        if (!a->alloc_calls[w]) continue;
        double pct = 100.0 * (double)a->alloc_calls[w] / (double)total;
        if (pct < 0.5) continue;
        printf("     %4u w (%5u B) : %10llu  %5.1f%%   peak-live %lld\n",
               w, (unsigned)(w * sizeof(Word_t)),
               (unsigned long long)a->alloc_calls[w], pct,
               (long long)a->peak_blocks[w]);
        shown++;
    }
    unsigned distinct = 0;
    unsigned maxw = 0;
    for (unsigned w = 0; w <= ARENA_MAX_WORDS; w++)
        if (a->alloc_calls[w]) { distinct++; maxw = w; }
    printf("     ... %u distinct size classes in use, largest = %u words (%u B)\n",
           distinct, maxw, (unsigned)(maxw * sizeof(Word_t)));
    printf("     (%u classes shown at >=0.5%%)\n", shown);
}

/* ------------------------------------------------------------------ */

typedef struct {
    uint64_t allocs, frees, reused, bumped;
    uint64_t peak_live_bytes, footprint, live_bytes_end;
} run_stats_t;

/* Build a tree of n keys, then delete a fraction, then re-insert fresh keys.
 * `churn_rounds` models TTL expiry: each round evicts `evict` keys and inserts
 * the same number of new ones, so the live population is flat. */
static run_stats_t run_workload(arena_mode_t mode, int sparse, unsigned n,
                                int churn_rounds, unsigned evict_per_round,
                                arena_hdr_t **keep, const char *hist_label) {
    arena_hdr_t *a = arena_create(ARENA_BYTES, mode);
    if (!a) { fprintf(stderr, "arena_create failed\n"); exit(2); }
    arena_install(a);

    Pvoid_t L = NULL;
    Word_t *pv;
    int rc;

    for (unsigned i = 0; i < n; i++) {
        Word_t k = keygen(i, sparse);
        JLI(pv, L, k);
        if (pv == PJERR) { fprintf(stderr, "PJERR (arena OOM?)\n"); break; }
        *pv = k;
    }

    if (hist_label) report_histogram(a, hist_label);

    /* TTL-style churn: steady live population, continuous alloc/free traffic */
    unsigned next_new = n;
    for (int r = 0; r < churn_rounds; r++) {
        unsigned base = (unsigned)r * evict_per_round;
        for (unsigned j = 0; j < evict_per_round; j++) {
            JLD(rc, L, keygen(base + j, sparse));
        }
        for (unsigned j = 0; j < evict_per_round; j++) {
            Word_t k = keygen(next_new++, sparse);
            JLI(pv, L, k);
            if (pv == PJERR) break;
            *pv = k;
        }
    }

    run_stats_t s;
    s.allocs = a->n_alloc;
    s.frees = a->n_free;
    s.reused = a->n_reused;
    s.bumped = a->n_bumped;
    s.peak_live_bytes = a->peak_live_bytes;
    s.footprint = a->bump;
    s.live_bytes_end = a->live_bytes;

    if (keep) { *keep = a; }
    else { JLFA(rc, L); (void)rc; arena_install(NULL); arena_destroy(a); }
    return s;
}

int main(void) {
    printf("== Gate 4: arena management & fragmentation at %u keys ==\n", N_KEYS);

    /* ---- 4a: allocation profile, no churn ---- */
    printf("\n-- 4a: Judy allocation profile, %u keys, build-only --\n", N_KEYS);

    arena_hdr_t *a = NULL;
    run_stats_t dense = run_workload(ARENA_MODE_FREELIST, 0, N_KEYS, 0, 0, &a,
                                     "dense/sequential");
    printf("\n   dense : allocs=%llu frees=%llu  churn(free/alloc)=%.3f  "
           "footprint=%.1f MB live=%.1f MB\n",
           (unsigned long long)dense.allocs, (unsigned long long)dense.frees,
           (double)dense.frees / (double)dense.allocs,
           (double)dense.footprint / (1024 * 1024),
           (double)dense.live_bytes_end / (1024 * 1024));
    { int rc; Pvoid_t dummy = NULL; JLFA(rc, dummy); (void)rc; }
    arena_install(NULL); arena_destroy(a); a = NULL;

    run_stats_t sparse = run_workload(ARENA_MODE_FREELIST, 1, N_KEYS, 0, 0, &a,
                                      "sparse/random");
    printf("\n   sparse: allocs=%llu frees=%llu  churn(free/alloc)=%.3f  "
           "footprint=%.1f MB live=%.1f MB\n",
           (unsigned long long)sparse.allocs, (unsigned long long)sparse.frees,
           (double)sparse.frees / (double)sparse.allocs,
           (double)sparse.footprint / (1024 * 1024),
           (double)sparse.live_bytes_end / (1024 * 1024));
    arena_install(NULL); arena_destroy(a); a = NULL;

    /* ---- 4b/4c: bump-only vs freelist under TTL churn ---- */
    printf("\n-- 4b/4c: TTL-style churn, live population held flat --\n");
    printf("   workload: build %u keys, then %d rounds x evict+insert %u keys\n",
           N_KEYS / 2, 20, N_KEYS / 40);
    printf("   (total churn traffic ~= %u insert+delete pairs)\n",
           20 * (N_KEYS / 40));

    struct { arena_mode_t m; const char *name; } modes[] = {
        { ARENA_MODE_FREELIST, "size-class freelist" },
        { ARENA_MODE_BUMP,     "bump-only (no reuse)" },
    };

    printf("\n   %-22s %12s %12s %12s %10s %10s\n", "arena mode", "allocs",
           "reused", "footprint", "live", "waste x");
    for (size_t i = 0; i < 2; i++) {
        run_stats_t s = run_workload(modes[i].m, 1, N_KEYS / 2, 20, N_KEYS / 40,
                                     NULL, NULL);
        double fp = (double)s.footprint / (1024 * 1024);
        double live = (double)s.live_bytes_end / (1024 * 1024);
        printf("   %-22s %12llu %12llu %9.1f MB %7.1f MB %9.2fx\n",
               modes[i].name, (unsigned long long)s.allocs,
               (unsigned long long)s.reused, fp, live,
               live > 0 ? fp / live : 0.0);
    }

    /* ---- 4c-extended: sustained churn, how far does bump-only run? ---- */
    printf("\n-- 4c: sustained churn scaling (freelist vs bump-only) --\n");
    printf("   %-22s %8s %12s %12s %10s\n", "arena mode", "rounds", "allocs",
           "footprint", "live");
    int rounds[] = { 10, 40, 160 };
    for (size_t i = 0; i < 2; i++) {
        for (size_t r = 0; r < sizeof rounds / sizeof rounds[0]; r++) {
            run_stats_t s = run_workload(modes[i].m, 1, 200000, rounds[r], 5000,
                                         NULL, NULL);
            printf("   %-22s %8d %12llu %9.1f MB %7.1f MB\n",
                   modes[i].name, rounds[r], (unsigned long long)s.allocs,
                   (double)s.footprint / (1024 * 1024),
                   (double)s.live_bytes_end / (1024 * 1024));
        }
    }

    printf("\nGATE 4: see FINDINGS.md\n");
    return 0;
}
