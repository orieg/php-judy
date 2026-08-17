/* Gate measurement for issue #85 step B3 ("mirror the payload into key_index").
 *
 * B3 wants ordered traversal of the *_HASH / *_ADAPTIVE types to read the
 * value straight out of the JudySL key_index cursor instead of doing a second
 * JHSG per element. That is a read-path win. The open question is what it
 * costs the WRITE path: to mirror the payload you must locate the key_index
 * slot on every write, including overwrite, where today it is not touched.
 *
 * The hope is that the swap is free because the existence probe can move from
 * JudyHS to JudySL — one call that answers "does this key exist?" AND hands
 * back the mirror slot, rather than a third lookup. Whether that holds depends
 * on random-order JSLG vs random-order JHSG, which issue #85 never measured:
 * its table compares the two REPLAYED IN ITERATION ORDER, and writes are
 * random-order.
 *
 * Cases, all over the same key set:
 *
 *   probe, hit    JHSG / JSLG / JSLI-on-existing, random order and iteration
 *                 order. This is the overwrite decision.
 *   probe, miss   JHSG / JSLG on keys that are not present. This is the
 *                 insert decision (insert already pays a JSLI afterwards).
 *   overwrite     end to end, as the extension would run it:
 *                   A = JHSG probe + JHSI + one store        (today)
 *                   B = JSLG probe + JHSI + two stores       (B3, probe swap)
 *                   C = JSLI + JHSG probe + JHSI + 2 stores  (B3, keep JHSG)
 *                 ABBA-interleaved and order-alternated across reps.
 *   insert        the same two arms on empty stores, random insertion order,
 *                 including the key_index JSLI that both arms already pay.
 *                 Insert is where the probe swap should WIN: a trie miss
 *                 fails at the first differing byte, a hash miss digests the
 *                 whole key first.
 *   JLG           only when keylen < 8, i.e. the ADAPTIVE short-string (SSO)
 *                 branch, whose probe today is a JudyL lookup on the packed
 *                 index rather than a JHSG.
 *
 * ./probebench <n> <keylen> <reps>
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <Judy.h>

static double now_ns(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (double)ts.tv_sec * 1e9 + (double)ts.tv_nsec;
}

static int cmpd(const void *a, const void *b) {
    double x = *(const double *)a, y = *(const double *)b;
    return (x > y) - (x < y);
}
static double med(double *v, int k) { qsort(v, k, sizeof(double), cmpd); return v[k / 2]; }

#define MAXK 128
#define MAXREPS 64

/* Same key shape as research/iteration-cost/iterbench.c, so the numbers here
 * sit next to the ones already in issue #85: "user:00001234:f5" padded with
 * 'x' to keylen. 10 keys share each user: prefix. */
static void make_key(char *buf, unsigned long i, int keylen) {
    int m = snprintf(buf, MAXK, "user:%08lu:f%lu", i / 10, i % 10);
    while (m < keylen) buf[m++] = 'x';
    buf[m] = '\0';
}

/* Absent keys with the same shape and the same trie depth, drawn from an
 * index range disjoint from the populated one. */
static void make_absent_key(char *buf, unsigned long i, int keylen) {
    make_key(buf, i + 500000000UL, keylen);
}

static unsigned long xs(unsigned long *s) {
    *s ^= *s << 13; *s ^= *s >> 7; *s ^= *s << 17;
    return *s;
}

int main(int argc, char **argv) {
    unsigned long n = (argc > 1) ? strtoul(argv[1], NULL, 10) : 1000000;
    int keylen = (argc > 2) ? atoi(argv[2]) : 16;
    int reps = (argc > 3) ? atoi(argv[3]) : 5;
    char key[MAXK];
    PWord_t pv;

    if (reps > MAXREPS) reps = MAXREPS;
    if (keylen >= MAXK - 1) { fprintf(stderr, "keylen too large\n"); return 1; }

    /* sl  = the JudySL key_index (B3 would store the payload in its value word)
     * hs  = the JudyHS value store used by *_INT_HASH and by ADAPTIVE long keys
     * jl  = the JudyL SSO value store used by ADAPTIVE short keys (keylen < 8) */
    Pvoid_t sl = (Pvoid_t)NULL, hs = (Pvoid_t)NULL, jl = (Pvoid_t)NULL;
    int sso = (keylen < 8);

    for (unsigned long i = 0; i < n; i++) {
        make_key(key, i, keylen);
        JSLI(pv, sl, (uint8_t *)key);
        if (pv == PJERR) { fprintf(stderr, "JSLI OOM\n"); return 1; }
        *pv = i + 1;
        JHSI(pv, hs, key, (Word_t)strlen(key));
        if (pv == PJERR) { fprintf(stderr, "JHSI OOM\n"); return 1; }
        *pv = i + 1;
        if (sso) {
            Word_t idx = 0;
            memcpy(&idx, key, strlen(key));
            JLI(pv, jl, idx);
            if (pv == PJERR) { fprintf(stderr, "JLI OOM\n"); return 1; }
            *pv = i + 1;
        }
    }

    /* Keys materialised in trie order, so "iteration order" replay is exactly
     * what ordered traversal would hand the value store. */
    char *keys = malloc((size_t)n * MAXK);
    char *absent = malloc((size_t)n * MAXK);
    if (!keys || !absent) { fprintf(stderr, "OOM\n"); return 1; }
    {
        unsigned long c = 0;
        key[0] = '\0';
        JSLF(pv, sl, (uint8_t *)key);
        while (pv != NULL && c < n) {
            strcpy(keys + (size_t)c * MAXK, key);
            c++;
            JSLN(pv, sl, (uint8_t *)key);
        }
        if (c != n) { fprintf(stderr, "walk got %lu of %lu\n", c, n); return 1; }
    }
    for (unsigned long i = 0; i < n; i++) {
        make_absent_key(absent + (size_t)i * MAXK, i, keylen);
        /* sanity: must really be absent */
        if (i < 4) {
            PWord_t chk;
            JSLG(chk, sl, (uint8_t *)(absent + (size_t)i * MAXK));
            if (chk != NULL) { fprintf(stderr, "absent key %lu is present\n", i); return 1; }
        }
    }

    /* A shuffled visit order, shared by every random-order case so they all see
     * the same sequence of keys and the same branch history. */
    unsigned long *order = malloc((size_t)n * sizeof(unsigned long));
    if (!order) { fprintf(stderr, "OOM\n"); return 1; }
    {
        unsigned long s = 88172645463325252UL;
        for (unsigned long i = 0; i < n; i++) order[i] = i;
        for (unsigned long i = n - 1; i > 0; i--) {
            unsigned long j = xs(&s) % (i + 1);
            unsigned long t = order[i]; order[i] = order[j]; order[j] = t;
        }
    }

    double r_hsg_rand[MAXREPS], r_slg_rand[MAXREPS], r_sli_rand[MAXREPS];
    double r_hsg_iter[MAXREPS], r_slg_iter[MAXREPS];
    double r_hsg_miss[MAXREPS], r_slg_miss[MAXREPS];
    double r_ovw_a[MAXREPS], r_ovw_b[MAXREPS], r_ovw_c[MAXREPS];
    double r_ins_a[MAXREPS], r_ins_b[MAXREPS];
    double r_jlg_rand[MAXREPS];
    unsigned long sink = 0;

    for (int r = 0; r < reps; r++) {
        double t0, t1;
        const char *k;

        /* ---- probe, hit, random order ---- */
        t0 = now_ns();
        for (unsigned long i = 0; i < n; i++) {
            k = keys + (size_t)order[i] * MAXK;
            JHSG(pv, hs, (void *)k, (Word_t)strlen(k));
            if (pv) sink += *pv;
        }
        t1 = now_ns(); r_hsg_rand[r] = (t1 - t0) / (double)n;

        t0 = now_ns();
        for (unsigned long i = 0; i < n; i++) {
            k = keys + (size_t)order[i] * MAXK;
            JSLG(pv, sl, (uint8_t *)k);
            if (pv) sink += *pv;
        }
        t1 = now_ns(); r_slg_rand[r] = (t1 - t0) / (double)n;

        /* JSLI on a key that is already present: the "insert unconditionally,
         * decide newness afterwards" variant. Must not grow the tree. */
        t0 = now_ns();
        for (unsigned long i = 0; i < n; i++) {
            k = keys + (size_t)order[i] * MAXK;
            JSLI(pv, sl, (uint8_t *)k);
            if (pv && pv != PJERR) sink += *pv;
        }
        t1 = now_ns(); r_sli_rand[r] = (t1 - t0) / (double)n;

        if (sso) {
            t0 = now_ns();
            for (unsigned long i = 0; i < n; i++) {
                Word_t idx = 0;
                k = keys + (size_t)order[i] * MAXK;
                memcpy(&idx, k, strlen(k));
                JLG(pv, jl, idx);
                if (pv) sink += *pv;
            }
            t1 = now_ns(); r_jlg_rand[r] = (t1 - t0) / (double)n;
        } else {
            r_jlg_rand[r] = 0.0;
        }

        /* ---- probe, hit, iteration order (comparable to issue #85) ---- */
        t0 = now_ns();
        for (unsigned long i = 0; i < n; i++) {
            k = keys + (size_t)i * MAXK;
            JHSG(pv, hs, (void *)k, (Word_t)strlen(k));
            if (pv) sink += *pv;
        }
        t1 = now_ns(); r_hsg_iter[r] = (t1 - t0) / (double)n;

        t0 = now_ns();
        for (unsigned long i = 0; i < n; i++) {
            k = keys + (size_t)i * MAXK;
            JSLG(pv, sl, (uint8_t *)k);
            if (pv) sink += *pv;
        }
        t1 = now_ns(); r_slg_iter[r] = (t1 - t0) / (double)n;

        /* ---- probe, miss, random order (the insert path's probe) ---- */
        t0 = now_ns();
        for (unsigned long i = 0; i < n; i++) {
            k = absent + (size_t)order[i] * MAXK;
            JHSG(pv, hs, (void *)k, (Word_t)strlen(k));
            if (pv) sink += *pv;
        }
        t1 = now_ns(); r_hsg_miss[r] = (t1 - t0) / (double)n;

        t0 = now_ns();
        for (unsigned long i = 0; i < n; i++) {
            k = absent + (size_t)order[i] * MAXK;
            JSLG(pv, sl, (uint8_t *)k);
            if (pv) sink += *pv;
        }
        t1 = now_ns(); r_slg_miss[r] = (t1 - t0) / (double)n;

        /* ---- end-to-end overwrite, ABBA-alternated by rep parity ---- */
        for (int pass = 0; pass < 3; pass++) {
            /* rep parity rotates ABC / CBA so neither arm always runs first */
            int which = (r % 2 == 0) ? pass : (2 - pass);

            if (which == 0) {           /* A: today's INT_HASH overwrite */
                t0 = now_ns();
                for (unsigned long i = 0; i < n; i++) {
                    PWord_t ex, slot;
                    unsigned long o = order[i];
                    k = keys + (size_t)o * MAXK;
                    Word_t kl = (Word_t)strlen(k);
                    JHSG(ex, hs, (void *)k, kl);
                    JHSI(slot, hs, (void *)k, kl);
                    if (slot && slot != PJERR) { *slot = o + 1; sink += (ex != NULL); }
                }
                t1 = now_ns(); r_ovw_a[r] = (t1 - t0) / (double)n;
            } else if (which == 1) {    /* B: B3 with the probe swapped to JSLG */
                t0 = now_ns();
                for (unsigned long i = 0; i < n; i++) {
                    PWord_t ks, slot;
                    unsigned long o = order[i];
                    k = keys + (size_t)o * MAXK;
                    Word_t kl = (Word_t)strlen(k);
                    JSLG(ks, sl, (uint8_t *)k);
                    JHSI(slot, hs, (void *)k, kl);
                    if (slot && slot != PJERR && ks != NULL) {
                        *slot = o + 1; *ks = o + 1;
                    }
                }
                t1 = now_ns(); r_ovw_b[r] = (t1 - t0) / (double)n;
            } else {                    /* C: B3 keeping the JHSG probe */
                t0 = now_ns();
                for (unsigned long i = 0; i < n; i++) {
                    PWord_t ex, ks, slot;
                    unsigned long o = order[i];
                    k = keys + (size_t)o * MAXK;
                    Word_t kl = (Word_t)strlen(k);
                    JHSG(ex, hs, (void *)k, kl);
                    JHSI(slot, hs, (void *)k, kl);
                    JSLI(ks, sl, (uint8_t *)k);
                    if (slot && slot != PJERR && ks != PJERR) {
                        *slot = o + 1; *ks = o + 1; sink += (ex != NULL);
                    }
                }
                t1 = now_ns(); r_ovw_c[r] = (t1 - t0) / (double)n;
            }
        }

        /* ---- end-to-end insert into empty stores, order-alternated ---- */
        for (int pass = 0; pass < 2; pass++) {
            int which = (r % 2 == 0) ? pass : (1 - pass);
            Pvoid_t s2 = (Pvoid_t)NULL, h2 = (Pvoid_t)NULL;
            Word_t freed;

            if (which == 0) {           /* A: today's insert */
                t0 = now_ns();
                for (unsigned long i = 0; i < n; i++) {
                    PWord_t ex, slot, ks;
                    unsigned long o = order[i];
                    k = keys + (size_t)o * MAXK;
                    Word_t kl = (Word_t)strlen(k);
                    JHSG(ex, h2, (void *)k, kl);
                    JHSI(slot, h2, (void *)k, kl);
                    if (slot && slot != PJERR) *slot = o + 1;
                    if (ex == NULL) { JSLI(ks, s2, (uint8_t *)k); sink += (ks != PJERR); }
                }
                t1 = now_ns(); r_ins_a[r] = (t1 - t0) / (double)n;
            } else {                    /* B: B3, probe swapped to the key_index */
                t0 = now_ns();
                for (unsigned long i = 0; i < n; i++) {
                    PWord_t ks, slot;
                    unsigned long o = order[i];
                    k = keys + (size_t)o * MAXK;
                    Word_t kl = (Word_t)strlen(k);
                    JSLG(ks, s2, (uint8_t *)k);
                    JHSI(slot, h2, (void *)k, kl);
                    if (slot && slot != PJERR) *slot = o + 1;
                    if (ks == NULL) JSLI(ks, s2, (uint8_t *)k);
                    if (ks && ks != PJERR) *ks = o + 1;
                }
                t1 = now_ns(); r_ins_b[r] = (t1 - t0) / (double)n;
            }
            JSLFA(freed, s2); sink += freed;
            JHSFA(freed, h2); sink += freed;
        }
    }

    printf("n=%lu keylen=%d reps=%d (median ns/op; min..max)\n", n, keylen, reps);
#define REPORT(label, arr) do { \
        double tmp[MAXREPS]; memcpy(tmp, arr, sizeof(double) * reps); \
        double m = med(tmp, reps); \
        printf("  %-34s %8.2f   [%.2f .. %.2f]\n", label, m, tmp[0], tmp[reps - 1]); \
    } while (0)
    REPORT("JHSG hit, random order", r_hsg_rand);
    REPORT("JSLG hit, random order", r_slg_rand);
    REPORT("JSLI on existing, random order", r_sli_rand);
    if (sso) REPORT("JLG hit (SSO packed), random order", r_jlg_rand);
    REPORT("JHSG hit, iteration order", r_hsg_iter);
    REPORT("JSLG hit, iteration order", r_slg_iter);
    REPORT("JHSG miss, random order", r_hsg_miss);
    REPORT("JSLG miss, random order", r_slg_miss);
    REPORT("overwrite A: JHSG+JHSI (today)", r_ovw_a);
    REPORT("overwrite B: JSLG+JHSI (B3 swap)", r_ovw_b);
    REPORT("overwrite C: JHSG+JHSI+JSLI (B3 add)", r_ovw_c);
    REPORT("insert A: JHSG+JHSI+JSLI (today)", r_ins_a);
    REPORT("insert B: JSLG+JHSI+JSLI (B3 swap)", r_ins_b);
    fprintf(stderr, "sink=%lu\n", sink);

    free(keys); free(absent); free(order);
    return 0;
}
