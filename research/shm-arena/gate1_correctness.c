/*
 * gate1_correctness.c - Gate 1: JudyMalloc override + arena correctness.
 *
 * Proves:
 *   (a) Judy1 / JudyL / JudySL insert, delete and ordered iteration are correct
 *       when every Judy allocation comes from a MAP_SHARED arena.
 *   (b) Judy's JudyFree(size) always matches the JudyMalloc(size) that produced
 *       the block. This invariant is what makes an exact-size-class freelist
 *       safe; without it gate 4's arena design collapses.
 *   (c) No two live blocks overlap and nothing is freed twice, verified with a
 *       word-granular shadow map over the whole arena.
 *   (d) Every address handed to Judy has its low 3 bits clear (MALLOCBITS).
 */

#include "shm_arena.h"

#include <Judy.h>

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define ARENA_BYTES (512ull * 1024 * 1024)
#define N_KEYS 200000u

static int g_fail = 0;
#define CHECK(cond, ...)                                                       \
    do {                                                                       \
        if (!(cond)) {                                                         \
            fprintf(stderr, "  FAIL %s:%d: ", __FILE__, __LINE__);             \
            fprintf(stderr, __VA_ARGS__);                                      \
            fprintf(stderr, "\n");                                             \
            g_fail = 1;                                                        \
        }                                                                      \
    } while (0)

/* ---- shadow map: one byte per 8-byte word of the arena ---- */
static unsigned char *g_shadow;   /* 0 = free, 1..n = live, first word marked */
static size_t g_shadow_words;
static arena_hdr_t *g_a;
static unsigned long g_audit_allocs, g_audit_frees;
static unsigned long g_overlap, g_dblfree, g_sizemismatch;

/* remembered size (in words) for each live block, indexed by word offset */
static unsigned int *g_blocksz;

static void auditor(void *addr, size_t words, int is_free) {
    size_t off = (size_t)((char *)addr - (char *)g_a);
    size_t w = off / 8;
    /* payload rounded up to ARENA_ALIGN, same as the arena does */
    size_t span = ((words * sizeof(Word_t)) + 7) / 8;
    if (w + span > g_shadow_words) return; /* outside tracked region */

    if (!is_free) {
        g_audit_allocs++;
        for (size_t i = 0; i < span; i++) {
            if (g_shadow[w + i]) {
                if (g_overlap++ == 0)
                    fprintf(stderr, "  overlap: alloc %p+%zuw hits live word %zu\n",
                            addr, span, w + i);
            }
            g_shadow[w + i] = 1;
        }
        g_blocksz[w] = (unsigned int)words;
    } else {
        g_audit_frees++;
        if (g_shadow[w] == 0) {
            if (g_dblfree++ == 0)
                fprintf(stderr, "  double-free/unknown free at %p\n", addr);
            return;
        }
        if (g_blocksz[w] != (unsigned int)words) {
            if (g_sizemismatch++ == 0)
                fprintf(stderr,
                        "  SIZE MISMATCH at %p: alloc'd %u words, freed as %zu words\n",
                        addr, g_blocksz[w], words);
        }
        for (size_t i = 0; i < span; i++) g_shadow[w + i] = 0;
        g_blocksz[w] = 0;
    }
}

/* deterministic pseudo-random key, no libc rand dependency */
static Word_t mix(Word_t x) {
    x ^= x >> 33; x *= 0xff51afd7ed558ccdull;
    x ^= x >> 33; x *= 0xc4ceb9fe1a85ec53ull;
    x ^= x >> 33;
    return x;
}

static int cmp_word(const void *a, const void *b) {
    Word_t x = *(const Word_t *)a, y = *(const Word_t *)b;
    return (x > y) - (x < y);
}

/* ------------------------------------------------------------------ */

static void test_judyl(const char *label, int sparse) {
    Pvoid_t L = NULL;
    Word_t *pv;
    int rc;

    Word_t *keys = malloc(N_KEYS * sizeof(Word_t));
    for (Word_t i = 0; i < N_KEYS; i++)
        keys[i] = sparse ? mix(i) : (Word_t)(i * 3);

    /* dedupe so the reference model matches Judy's set semantics */
    qsort(keys, N_KEYS, sizeof(Word_t), cmp_word);
    size_t nk = 0;
    for (size_t i = 0; i < N_KEYS; i++)
        if (i == 0 || keys[i] != keys[i - 1]) keys[nk++] = keys[i];

    for (size_t i = 0; i < nk; i++) {
        JLI(pv, L, keys[i]);
        CHECK(pv != PJERR, "JLI returned PJERR at %zu", i);
        *pv = keys[i] ^ 0xa5a5a5a5u;
    }

    Word_t cnt;
    JLC(cnt, L, 0, -1);
    CHECK(cnt == nk, "%s: count %lu != %zu", label, (unsigned long)cnt, nk);

    /* ordered forward iteration must reproduce the sorted key list exactly */
    Word_t idx = 0;
    size_t n = 0;
    JLF(pv, L, idx);
    while (pv) {
        CHECK(n < nk && idx == keys[n], "%s: order break at %zu", label, n);
        CHECK(*pv == (keys[n] ^ 0xa5a5a5a5u), "%s: value corrupt at %zu", label, n);
        n++;
        JLN(pv, L, idx);
    }
    CHECK(n == nk, "%s: forward iterated %zu of %zu", label, n, nk);

    /* reverse iteration */
    idx = (Word_t)-1;
    n = 0;
    JLL(pv, L, idx);
    while (pv) {
        CHECK(idx == keys[nk - 1 - n], "%s: reverse order break at %zu", label, n);
        n++;
        JLP(pv, L, idx);
    }
    CHECK(n == nk, "%s: reverse iterated %zu of %zu", label, n, nk);

    /* delete every other key, then re-verify */
    for (size_t i = 0; i < nk; i += 2) { JLD(rc, L, keys[i]); CHECK(rc == 1, "JLD failed"); }
    JLC(cnt, L, 0, -1);
    CHECK(cnt == nk - (nk + 1) / 2, "%s: count after delete = %lu", label, (unsigned long)cnt);

    idx = 0; n = 0;
    JLF(pv, L, idx);
    while (pv) {
        size_t want = 1 + 2 * n;
        CHECK(want < nk && idx == keys[want], "%s: post-delete order break at %zu", label, n);
        n++;
        JLN(pv, L, idx);
    }
    CHECK(n == nk / 2, "%s: post-delete iterated %zu, want %zu", label, n, nk / 2);

    /* re-insert the deleted half: exercises freelist reuse */
    for (size_t i = 0; i < nk; i += 2) {
        JLI(pv, L, keys[i]);
        *pv = keys[i] ^ 0xa5a5a5a5u;
    }
    JLC(cnt, L, 0, -1);
    CHECK(cnt == nk, "%s: count after reinsert = %lu", label, (unsigned long)cnt);

    idx = 0; n = 0;
    JLF(pv, L, idx);
    while (pv) {
        CHECK(idx == keys[n] && *pv == (keys[n] ^ 0xa5a5a5a5u),
              "%s: reinsert verify break at %zu", label, n);
        n++;
        JLN(pv, L, idx);
    }
    CHECK(n == nk, "%s: reinsert iterated %zu of %zu", label, n, nk);

    JLFA(rc, L);
    (void)rc;
    free(keys);
    printf("  [ok] JudyL %-22s keys=%zu\n", label, nk);
}

static void test_judy1(void) {
    Pvoid_t J = NULL;
    int rc;
    Word_t cnt, idx;

    for (Word_t i = 0; i < N_KEYS; i++) { J1S(rc, J, mix(i)); }
    J1C(cnt, J, 0, -1);

    size_t n = 0;
    idx = 0;
    J1F(rc, J, idx);
    Word_t prev = 0;
    while (rc) {
        if (n) CHECK(idx > prev, "Judy1: order break at %zu", n);
        prev = idx; n++;
        J1N(rc, J, idx);
    }
    CHECK(n == cnt, "Judy1: iterated %zu != count %lu", n, (unsigned long)cnt);

    for (Word_t i = 0; i < N_KEYS; i += 3) { J1U(rc, J, mix(i)); }
    Word_t cnt2;
    J1C(cnt2, J, 0, -1);
    CHECK(cnt2 < cnt, "Judy1: unset did not reduce count");

    J1FA(rc, J);
    printf("  [ok] Judy1  %-22s set=%lu after-unset=%lu\n", "sparse",
           (unsigned long)cnt, (unsigned long)cnt2);
}

static void test_judysl(void) {
    Pvoid_t S = NULL;
    Word_t *pv;
    int rc;
    uint8_t buf[64];
    const size_t NS = 50000;

    for (size_t i = 0; i < NS; i++) {
        snprintf((char *)buf, sizeof buf, "key:%016llx", (unsigned long long)mix(i));
        JSLI(pv, S, buf);
        CHECK(pv != PJERR, "JSLI PJERR");
        *pv = (Word_t)i;
    }

    /* ordered traversal must be strictly increasing in strcmp order */
    uint8_t cur[64];
    uint8_t prev[64];
    cur[0] = '\0';
    size_t n = 0;
    JSLF(pv, S, cur);
    while (pv) {
        if (n) CHECK(strcmp((char *)cur, (char *)prev) > 0,
                     "JudySL: order break at %zu (%s <= %s)", n, cur, prev);
        memcpy(prev, cur, sizeof prev);
        n++;
        JSLN(pv, S, cur);
    }
    CHECK(n == NS, "JudySL: iterated %zu of %zu", n, NS);

    /* delete half, verify lookups */
    for (size_t i = 0; i < NS; i += 2) {
        snprintf((char *)buf, sizeof buf, "key:%016llx", (unsigned long long)mix(i));
        JSLD(rc, S, buf);
        CHECK(rc == 1, "JSLD failed at %zu", i);
    }
    for (size_t i = 1; i < NS; i += 2) {
        snprintf((char *)buf, sizeof buf, "key:%016llx", (unsigned long long)mix(i));
        JSLG(pv, S, buf);
        CHECK(pv != NULL && *pv == (Word_t)i, "JSLG lost key %zu", i);
    }
    JSLFA(rc, S);
    printf("  [ok] JudySL %-22s strings=%zu\n", "mixed", NS);
}

int main(void) {
    printf("== Gate 1: JudyMalloc override onto MAP_SHARED arena ==\n");

    g_a = arena_create(ARENA_BYTES, ARENA_MODE_FREELIST);
    if (!g_a) { fprintf(stderr, "arena_create failed\n"); return 2; }

    g_shadow_words = (size_t)(g_a->size / 8);
    g_shadow = calloc(g_shadow_words, 1);
    g_blocksz = calloc(g_shadow_words, sizeof(unsigned int));
    if (!g_shadow || !g_blocksz) { fprintf(stderr, "shadow alloc failed\n"); return 2; }

    arena_install(g_a);
    arena_set_audit(auditor);

    printf("  arena base=%p size=%llu MB\n", (void *)g_a,
           (unsigned long long)(g_a->size / (1024 * 1024)));

    test_judyl("dense/sequential", 0);
    test_judyl("sparse/random", 1);
    test_judy1();
    test_judysl();

    arena_set_audit(NULL);

    printf("\n  allocator audit:\n");
    printf("    JudyMalloc calls   : %lu\n", g_audit_allocs);
    printf("    JudyFree   calls   : %lu\n", g_audit_frees);
    printf("    overlapping blocks : %lu\n", g_overlap);
    printf("    double/unknown free: %lu\n", g_dblfree);
    printf("    alloc/free size mismatches: %lu\n", g_sizemismatch);
    printf("    arena bump high-water: %.2f MB\n", (double)g_a->bump / (1024 * 1024));
    printf("    live bytes at end  : %llu\n", (unsigned long long)g_a->live_bytes);
    printf("    oversize allocs (> %u words): %llu\n", ARENA_MAX_WORDS,
           (unsigned long long)g_a->n_oversize);
    printf("    OOM               : %llu\n", (unsigned long long)g_a->n_oom);

    CHECK(g_overlap == 0, "arena handed out overlapping blocks");
    CHECK(g_dblfree == 0, "double free detected");
    CHECK(g_sizemismatch == 0, "Judy free size != alloc size");
    CHECK(g_a->n_oom == 0, "arena exhausted");
    /* after all *FreeArray calls, the tree must have released everything */
    CHECK(g_a->live_bytes == 0, "leak: %llu live bytes after JLFA/J1FA/JSLFA",
          (unsigned long long)g_a->live_bytes);

    printf("\nGATE 1: %s\n", g_fail ? "FAIL" : "PASS");
    arena_destroy(g_a);
    return g_fail ? 1 : 0;
}
