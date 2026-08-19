/* Discriminator bench for the "JSLN key-reconstruction is the cost" hypothesis.
 *
 * Tests that separate the candidate explanations:
 *   H1 key reconstruction: JSLN writes the next key into the caller buffer.
 *      -> cost should scale with key length, and JSLN should be much more
 *         expensive than JSLG on the same key in the same order.
 *   H2 memory-bound re-descend: every JSLN/JSLG restarts at the root.
 *      -> cost should scale with n (working set vs cache), and JSLN ~= JSLG.
 *
 * ./iterbench <n> <keylen> <reps> [corpus]
 *
 * corpus is one of:
 *   struct   (default) the original two-shape corpus, identical to
 *            tools/write-probe-cost/probebench.c so the two harnesses agree
 *            byte for byte: "user:00001234:f5" padded with 'x' at
 *            keylen >= 16, a fixed-width mixed base-36 counter below it.
 *   rand     uniform-random bytes, exactly keylen of them, drawn from 1..255.
 *            One shape across the whole sweep, so key length is the only
 *            variable and there is no regime break at 16.
 *   varlen   uniform-random bytes with the length itself drawn uniformly from
 *            [4, keylen]. Deliberately ragged, to exercise a mix of trie
 *            depths rather than a single uniform depth.
 *
 * Why `struct` needs two shapes: the long format is 16 characters at minimum
 * ("user:" + 8 digits + ":f" + 1 digit), so it cannot express a shorter key at
 * all. Before issue #122 make_key() only padded a key *up* and never
 * truncated, so every requested keylen at or below 16 silently produced the
 * same 16-byte key -- the short half of every key-length sweep run through
 * this harness never actually executed. See also issue #118 / PR #124, which
 * fixed the same defect in probebench.c.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <limits.h>
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

#define CORPUS_STRUCT 0
#define CORPUS_RAND   1
#define CORPUS_VARLEN 2
static const char *corpus_name[] = { "struct", "rand", "varlen" };

/* Two key shapes, split at keylen = 16. Identical to probebench.c. */
#define LONGKEY_MIN 16
#define KEY_RADIX 36
static const char key_alphabet[] = "0123456789abcdefghijklmnopqrstuvwxyz";

/* An exact-length guard that survives -DNDEBUG. assert() is not used: this
 * repo compiles asserts out in places, and a generator that silently ignores
 * the requested length is exactly the defect being fixed here (issue #122), so
 * the check has to be unconditional. */
static void key_check(const char *buf, int want) {
    size_t got = strlen(buf);
    if ((int)got != want) {
        fprintf(stderr, "make_key: emitted %zu bytes, wanted %d (\"%s\")\n",
                got, want, buf);
        abort();
    }
}

/* How many distinct keys the short regime can express, saturating rather than
 * overflowing. Used to reject an (n, keylen) pair that cannot hold n keys
 * without collisions. */
static unsigned long short_key_space(int keylen) {
    unsigned long cap = 1;
    for (int p = 0; p < keylen; p++) {
        if (cap > ULONG_MAX / KEY_RADIX) return ULONG_MAX;
        cap *= KEY_RADIX;
    }
    return cap;
}

/* A multiplier coprime with 36^keylen, sized to the space so the mix actually
   spans it. A fixed constant does not: at keylen 12 the space is 36^12 ~ 4.7e18
   while i * 2654435761 tops out near 1.3e15 for a 500k corpus, leaving the two
   leading digits stuck at '0'. Scaling by the golden-ratio conjugate spreads
   indices across the whole range (Fibonacci hashing), and forcing the result
   odd and not divisible by 3 keeps gcd(mix, 36^k) = 1, so it stays a bijection.

   Above keylen 12 the space exceeds ULONG_MAX and a 64-bit index cannot reach
   the high digits at all -- those keys keep some leading '0's no matter what.
   That is a property of the index type, not something the mix can fix. */
static unsigned long key_mix_for(unsigned long cap)
{
    unsigned long m = (unsigned long)((long double) cap * 0.6180339887498949L);
    if (m < 3) return 1;
    if ((m & 1UL) == 0) m++;
    while (m % 3 == 0) m += 2;
    return m;
}

/* keylen >= LONGKEY_MIN keeps the original shape ("user:00001234:f5" padded
 * with 'x'; 10 keys share each user: prefix), so figures at 16/24/40/64 stay
 * on the same curve as the ones already published in issue #85.
 *
 * keylen < LONGKEY_MIN emits base-36 of a mixed index, filling exactly keylen
 * bytes. The mix spreads the index across every byte: encoding i directly
 * needs only ceil(log36(n)) digits, so the leading bytes would be a uniform
 * run of '0' and JudySL would compress it -- length would vary while entropy
 * stayed fixed. */
static void make_key(char *buf, unsigned long i, int keylen) {
    unsigned long v, cap, mix;

    if (keylen >= LONGKEY_MIN) {
        int m = snprintf(buf, MAXK, "user:%08lu:f%lu", i / 10, i % 10);
        while (m < keylen) buf[m++] = 'x';
        buf[m] = '\0';
        key_check(buf, keylen);
        return;
    }

    cap = short_key_space(keylen);
    mix = key_mix_for(cap);
    if (cap == ULONG_MAX) {
        v = i * mix;                      /* wrap mod 2^64; mix odd => bijective */
    } else {
        v = (unsigned long)(((unsigned __int128) i * mix) % cap);
    }

    for (int p = keylen - 1; p >= 0; p--) {
        buf[p] = key_alphabet[v % KEY_RADIX];
        v /= KEY_RADIX;
    }
    buf[keylen] = '\0';
    key_check(buf, keylen);
}

static unsigned long xs(unsigned long *s) {
    *s ^= *s << 13; *s ^= *s >> 7; *s ^= *s << 17;
    return *s;
}

/* How many distinct keys the random corpora can express at a given length,
 * saturating rather than overflowing. 255 values per byte, NUL excluded.
 * Without this the redraw loop below spins forever once n approaches the
 * space -- a hang rather than a wrong number, but the same class of defect
 * as a generator that ignores its length argument (issue #122). */
static unsigned long rand_key_space(int len) {
    unsigned long cap = 1;
    for (int p = 0; p < len; p++) {
        if (cap > ULONG_MAX / 255) return ULONG_MAX;
        cap *= 255;
    }
    return cap;
}

/* Uniform-random bytes over 1..255. NUL is excluded because JudySL keys are
 * NUL-terminated. Every byte position carries ~8 bits, so no position is
 * invariant and the branch mix is not pinned to a single node type -- the
 * degeneracy that scoped the original #85 conclusion to one regime. */
static void make_random_key(char *buf, int len, unsigned long *seed) {
    for (int p = 0; p < len; p++) buf[p] = (char)(1 + (xs(seed) % 255));
    buf[len] = '\0';
    key_check(buf, len);
}

int main(int argc, char **argv) {
    unsigned long n = (argc > 1) ? strtoul(argv[1], NULL, 10) : 1000000;
    int keylen = (argc > 2) ? atoi(argv[2]) : 16;
    int reps = (argc > 3) ? atoi(argv[3]) : 5;
    int corpus = CORPUS_STRUCT;
    char key[MAXK];
    unsigned long gen_seed = 0x9e3779b97f4a7c15UL;
    unsigned long collisions = 0;

    if (argc > 4) {
        if      (!strcmp(argv[4], "struct")) corpus = CORPUS_STRUCT;
        else if (!strcmp(argv[4], "rand"))   corpus = CORPUS_RAND;
        else if (!strcmp(argv[4], "varlen")) corpus = CORPUS_VARLEN;
        else { fprintf(stderr, "corpus must be struct|rand|varlen\n"); return 1; }
    }

    if (reps > MAXREPS) reps = MAXREPS;
    if (keylen >= MAXK - 1) { fprintf(stderr, "keylen too large\n"); return 1; }
    if (keylen < 1) { fprintf(stderr, "keylen must be >= 1\n"); return 1; }
    if (corpus == CORPUS_VARLEN && keylen < 4) {
        fprintf(stderr, "varlen needs keylen >= 4\n"); return 1;
    }
    if (corpus != CORPUS_STRUCT) {
        /* varlen's shortest draw is 4 bytes, so that is where its space is
         * tightest. Demand headroom of 2n, not n: the redraw loop's expected
         * cost blows up as the corpus saturates its own key space. */
        int shortest = (corpus == CORPUS_VARLEN) ? 4 : keylen;
        unsigned long cap = rand_key_space(shortest);
        if (cap / 2 < n) {
            fprintf(stderr,
                "%s at keylen %d draws from only %lu distinct keys; need "
                "headroom for %lu. Raise keylen or lower n.\n",
                corpus_name[corpus], keylen, cap, n);
            return 1;
        }
    }
    /* The struct corpus's short regime encodes the index in base-36 across
     * keylen bytes, so it can only express so many distinct keys. n must fit,
     * or keys collide and every number below is meaningless. */
    if (corpus == CORPUS_STRUCT && keylen < LONGKEY_MIN) {
        unsigned long cap = short_key_space(keylen);
        if (cap < n) {
            fprintf(stderr,
                "keylen %d holds only %lu distinct keys; need %lu. "
                "Raise keylen or lower n.\n", keylen, cap, n);
            return 1;
        }
    }

    Pvoid_t sl = (Pvoid_t)NULL, jl = (Pvoid_t)NULL, hs = (Pvoid_t)NULL;
    PWord_t pv;

    /* build all three stores over the same n */
    for (unsigned long i = 0; i < n; i++) {
        if (corpus == CORPUS_STRUCT) {
            make_key(key, i, keylen);
            JSLI(pv, sl, (uint8_t *)key);
            if (pv == PJERR) { fprintf(stderr, "JSLI OOM\n"); return 1; }
        } else {
            /* Random corpora can collide; redraw until the key is new. The
             * value word of a freshly inserted JudySL slot is 0, and every
             * stored value below is i + 1 >= 1, so 0 means "new". */
            int len = keylen;
            for (;;) {
                if (corpus == CORPUS_VARLEN)
                    len = 4 + (int)(xs(&gen_seed) % (unsigned long)(keylen - 3));
                make_random_key(key, len, &gen_seed);
                JSLI(pv, sl, (uint8_t *)key);
                if (pv == PJERR) { fprintf(stderr, "JSLI OOM\n"); return 1; }
                if (*pv == 0) break;
                collisions++;
            }
        }
        *pv = i + 1;
        JHSI(pv, hs, key, (Word_t)strlen(key));
        if (pv == PJERR) { fprintf(stderr, "JHSI OOM\n"); return 1; }
        *pv = i + 1;
        JLI(pv, jl, (Word_t)i);
        if (pv == PJERR) { fprintf(stderr, "JLI OOM\n"); return 1; }
        *pv = i + 1;
    }

    /* sorted key list, for replaying iteration order as point lookups */
    char *keys = malloc((size_t)n * MAXK);
    if (!keys) { fprintf(stderr, "OOM\n"); return 1; }
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

    double r_jln[MAXREPS], r_jsln[MAXREPS], r_jslg_sorted[MAXREPS];
    double r_jslg_rand[MAXREPS], r_jhsg_sorted[MAXREPS];
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

    printf("n=%lu keylen=%d reps=%d corpus=%s (median ns/key; min..max)\n",
           n, keylen, reps, corpus_name[corpus]);
    if (corpus != CORPUS_STRUCT)
        printf("  (redraws to avoid duplicate keys: %lu)\n", collisions);
#define REPORT(label, arr) do { \
        double tmp[MAXREPS]; memcpy(tmp, arr, sizeof(double) * reps); \
        double m = med(tmp, reps); \
        printf("  %-28s %8.2f   [%.2f .. %.2f]\n", label, m, tmp[0], tmp[reps - 1]); \
    } while (0)
    REPORT("JLF/JLN iterate", r_jln);
    REPORT("JSLF/JSLN iterate", r_jsln);
    REPORT("JSLG sorted (no key recon)", r_jslg_sorted);
    REPORT("JSLG random", r_jslg_rand);
    REPORT("JHSG sorted", r_jhsg_sorted);
    fprintf(stderr, "sink=%lu\n", sink);

    /* Released rather than left to exit(2) so a leak checker has something to
     * say. long, not Word_t: the J*FA macros compare against JERR (-1). */
    { long freed;
      JSLFA(freed, sl); JHSFA(freed, hs); JLFA(freed, jl); (void)freed; }
    free(keys);
    return 0;
}
