/* Discriminator bench for the "JSLN key-reconstruction is the cost" hypothesis.
 *
 * Tests that separate the candidate explanations:
 *   H1 key reconstruction: JSLN writes the next key into the caller buffer.
 *      -> cost should scale with key length, and JSLN should be much more
 *         expensive than JSLG on the same key in the same order.
 *   H2 memory-bound re-descend: every JSLN/JSLG restarts at the root.
 *      -> cost should scale with n (working set vs cache), and JSLN ~= JSLG.
 *
 * ./iterbench <n> <keylen> <reps>
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

/* keylen = total chars incl. padding, excl. NUL. Prefix structure kept
 * realistic ("user:00001234:f5"), tail padded with 'x' to hit keylen. */
static void make_key(char *buf, unsigned long i, int keylen) {
    int m = snprintf(buf, MAXK, "user:%08lu:f%lu", i / 10, i % 10);
    while (m < keylen) buf[m++] = 'x';
    buf[m] = '\0';
}

int main(int argc, char **argv) {
    unsigned long n = (argc > 1) ? strtoul(argv[1], NULL, 10) : 1000000;
    int keylen = (argc > 2) ? atoi(argv[2]) : 16;
    int reps = (argc > 3) ? atoi(argv[3]) : 5;
    char key[MAXK];

    Pvoid_t sl = (Pvoid_t)NULL, jl = (Pvoid_t)NULL, hs = (Pvoid_t)NULL;
    PWord_t pv;

    /* build all three stores over the same n */
    for (unsigned long i = 0; i < n; i++) {
        make_key(key, i, keylen);
        JSLI(pv, sl, (uint8_t *)key); *pv = i + 1;
        JHSI(pv, hs, key, (Word_t)strlen(key)); *pv = i + 1;
        JLI(pv, jl, (Word_t)i); *pv = i + 1;
    }

    /* sorted key list, for replaying iteration order as point lookups */
    char *keys = malloc((size_t)n * MAXK);
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

    double r_jln[64], r_jsln[64], r_jslg_sorted[64], r_jslg_rand[64], r_jhsg_sorted[64];
    unsigned long sink = 0;

    for (int r = 0; r < reps; r++) {
        double t0, t1;

        /* JudyL ordered iteration */
        { Word_t idx = 0; unsigned long c = 0;
          t0 = now_ns();
          JLF(pv, jl, idx);
          while (pv != NULL) { sink += *pv; c++; JLN(pv, jl, idx); }
          t1 = now_ns(); r_jln[r] = (t1 - t0) / (double)c; }

        /* JudySL ordered iteration (descend + key reconstruction) */
        { unsigned long c = 0;
          t0 = now_ns();
          key[0] = '\0';
          JSLF(pv, sl, (uint8_t *)key);
          while (pv != NULL) { sink += *pv; c++; JSLN(pv, sl, (uint8_t *)key); }
          t1 = now_ns(); r_jsln[r] = (t1 - t0) / (double)c; }

        /* JudySL point lookup, replaying iteration order: same descend, same
         * locality, NO key reconstruction. JSLN - this = reconstruction cost. */
        { t0 = now_ns();
          for (unsigned long i = 0; i < n; i++) {
              JSLG(pv, sl, (uint8_t *)(keys + (size_t)i * MAXK));
              if (pv) sink += *pv;
          }
          t1 = now_ns(); r_jslg_sorted[r] = (t1 - t0) / (double)n; }

        /* JudySL point lookup, random order (locality control) */
        { unsigned long s = 88172645463325252UL;
          t0 = now_ns();
          for (unsigned long i = 0; i < n; i++) {
              s ^= s << 13; s ^= s >> 7; s ^= s << 17;
              JSLG(pv, sl, (uint8_t *)(keys + (size_t)(s % n) * MAXK));
              if (pv) sink += *pv;
          }
          t1 = now_ns(); r_jslg_rand[r] = (t1 - t0) / (double)n; }

        /* JudyHS lookup in sorted order (what *_HASH types pay per element) */
        { t0 = now_ns();
          for (unsigned long i = 0; i < n; i++) {
              const char *k = keys + (size_t)i * MAXK;
              JHSG(pv, hs, (void *)k, (Word_t)strlen(k));
              if (pv) sink += *pv;
          }
          t1 = now_ns(); r_jhsg_sorted[r] = (t1 - t0) / (double)n; }
    }

    printf("n=%lu keylen=%d reps=%d (median ns/key; min..max)\n", n, keylen, reps);
#define REPORT(label, arr) do { \
        double tmp[64]; memcpy(tmp, arr, sizeof(double) * reps); \
        double m = med(tmp, reps); \
        printf("  %-28s %8.2f   [%.2f .. %.2f]\n", label, m, tmp[0], tmp[reps - 1]); \
    } while (0)
    REPORT("JLF/JLN iterate", r_jln);
    REPORT("JSLF/JSLN iterate", r_jsln);
    REPORT("JSLG sorted (no key recon)", r_jslg_sorted);
    REPORT("JSLG random", r_jslg_rand);
    REPORT("JHSG sorted", r_jhsg_sorted);
    fprintf(stderr, "sink=%lu\n", sink);
    return 0;
}
