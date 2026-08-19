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
 * ./probebench <n> <keylen> <reps> [corpus] [absent]
 *
 * corpus is one of, matching tools/iteration-cost/iterbench.c exactly so
 * the two harnesses emit comparable corpora:
 *   struct   (default) the original two-shape corpus: "user:00001234:f5"
 *            padded with 'x' at keylen >= 16, a fixed-width mixed base-36
 *            counter below it.
 *   rand     uniform-random bytes, exactly keylen of them, drawn from 1..255.
 *            One shape across the whole sweep, so key length is the only
 *            variable and there is no regime break at 16.
 *   varlen   uniform-random bytes with the length itself drawn uniformly from
 *            [4, keylen]. Deliberately ragged, to exercise a mix of trie
 *            depths rather than a single uniform depth.
 *
 * absent selects how far into the key an absent key first differs from a
 * stored one. This is the independent variable of the miss comparison -- see
 * make_divergent_key() below for why a single setting of it is not a
 * measurement:
 *   offset   (default) the historical generator, kept so every miss figure
 *            published before this option existed stays reproducible.
 *   shallow  diverge at byte 0
 *   mid      diverge at byte len/2
 *   deep     diverge at byte len-2
 *   last     diverge at the final byte
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

/* Corpus selection, identical to tools/iteration-cost/iterbench.c. A single
 * corpus hides real effects: issue #122 / PR #139 found "JSLN is flat in key
 * length" to be a property of the structured corpus rather than of JudySL. */
#define CORPUS_STRUCT 0
#define CORPUS_RAND   1
#define CORPUS_VARLEN 2
static const char *corpus_name[] = { "struct", "rand", "varlen" };

/* Where an absent key first differs from a stored one. */
#define DIVERGE_OFFSET  0
#define DIVERGE_SHALLOW 1
#define DIVERGE_MID     2
#define DIVERGE_DEEP    3
#define DIVERGE_LAST    4
static const char *diverge_name[] = { "offset", "shallow", "mid", "deep", "last" };

/* The long format is at minimum 16 characters ("user:" + 8 digits + ":f" +
 * 1 digit), so it cannot express a shorter key at all. Keys below that width
 * therefore use a different shape: a fixed-width base-36 counter, which fills
 * exactly keylen bytes and stays unique. Two regimes, one boundary. */
#define LONGKEY_MIN 16
#define KEY_RADIX 36
static const char key_alphabet[] = "0123456789abcdefghijklmnopqrstuvwxyz";

/* An exact-length guard that survives -DNDEBUG. assert() is not used: this
 * repo compiles asserts out in places, and a generator that silently ignores
 * the requested length is exactly the defect issue #122 is about, so the check
 * has to be unconditional. Identical to iterbench.c's. */
static void key_check(const char *buf, int want) {
    size_t got = strlen(buf);
    if ((int)got != want) {
        fprintf(stderr, "make_key: emitted %zu bytes, wanted %d (\"%s\")\n",
                got, want, buf);
        abort();
    }
}

/* The DIVERGE_OFFSET absent-key generator draws from an index range disjoint
 * from the populated one. The long regime has room to spare; the short regime
 * does not, so it offsets by n and main() checks the space is big enough.
 *
 * This offset is NOT depth-neutral, and an earlier version of this file said
 * it was. Pushing i/10 into 8-digit territory changes the third byte of the
 * "user:%08lu" field, so at keylen >= LONGKEY_MIN every absent key diverges
 * from the stored set at byte 5 of 16 -- the earliest position the format can
 * express -- while JudyHS still digests all 16 bytes. Under this mode the
 * long-key miss comparison is therefore granted to the trie by construction.
 * The DIVERGE_* modes exist to measure it instead; see make_divergent_key(). */
#define ABSENT_OFFSET_LONG 500000000UL

/* keylen >= LONGKEY_MIN keeps the exact shape of
 * tools/iteration-cost/iterbench.c, so those numbers still sit next to the
 * ones in issue #85: "user:00001234:f5" padded with 'x'. 10 keys share each
 * user: prefix.
 *
 * keylen < LONGKEY_MIN emits base-36 of a mixed index (see key_mix_for), in
 * exactly keylen bytes. This is what makes the ADAPTIVE/SSO probe (keylen < 8)
 * reachable: before, the
 * format's 16 characters were emitted regardless of keylen, so every keylen
 * below 16 silently produced a 16-byte key and the SSO branch then memcpy'd
 * 16 bytes into an 8-byte Word_t. See issue #118. */
static unsigned long short_key_space(int keylen);

/* A multiplier coprime with 36^keylen, sized to the space so the mix actually
   spans it. A fixed constant does not: at keylen 12 the space is 36^12 ~ 4.7e18
   while i * 2654435761 tops out near 1.3e15 for a 500k corpus, leaving the two
   leading digits stuck at '0'. Scaling by the golden-ratio conjugate spreads
   indices across the whole range (Fibonacci hashing), and forcing the result
   odd and not divisible by 3 keeps gcd(mix, 36^k) = 1, so it stays a bijection.

   Above keylen 12 the space exceeds ULONG_MAX and a 64-bit index cannot reach
   the high digits at all — those keys keep some leading '0's no matter what.
   That is a property of the index type, not something the mix can fix. */
static unsigned long key_mix_for(unsigned long cap)
{
    unsigned long m = (unsigned long)((long double) cap * 0.6180339887498949L);
    if (m < 3) return 1;
    if ((m & 1UL) == 0) m++;
    while (m % 3 == 0) m += 2;
    return m;
}

static void make_key(char *buf, unsigned long i, int keylen) {
    unsigned long v;
    unsigned long cap;
    unsigned long mix;

    if (keylen >= LONGKEY_MIN) {
        int m = snprintf(buf, MAXK, "user:%08lu:f%lu", i / 10, i % 10);
        while (m < keylen) buf[m++] = 'x';
        buf[m] = '\0';
        key_check(buf, keylen);
        return;
    }

    /* Spread the index across every byte before encoding it.
     *
     * Encoding i directly leaves a uniform prefix: base-36 needs only
     * ceil(log36(n)) digits, so at n = 500k every keylen-8 key began with four
     * identical '0's and every keylen-12 key with eight. That is a degenerate
     * corpus for anything trie-shaped — JudySL compresses the shared run, so a
     * "longer key" run measured the same 4 bytes of entropy behind more
     * padding, and length and entropy could not be varied independently.
     *
     * KEY_MIX is odd and not divisible by 3, so it is coprime with 36^keylen
     * and i -> (i * KEY_MIX) mod 36^keylen is a bijection: every key stays
     * distinct, and the capacity guard in main() still bounds the space
     * exactly. Above keylen 12 the space exceeds ULONG_MAX, where the wrap
     * modulo 2^64 is itself bijective (KEY_MIX odd) and fits in keylen digits.
     */
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

/* How many distinct keys the short regime can express, saturating rather than
 * overflowing. Used to reject a (n, keylen) pair that cannot hold n present
 * plus n absent keys without collisions. */
static unsigned long short_key_space(int keylen) {
    unsigned long cap = 1;
    for (int p = 0; p < keylen; p++) {
        if (cap > ULONG_MAX / KEY_RADIX) return ULONG_MAX;
        cap *= KEY_RADIX;
    }
    return cap;
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
 * degeneracy that scoped the original issue #85 conclusion to one regime.
 * Identical to iterbench.c's. */
static void make_random_key(char *buf, int len, unsigned long *seed) {
    for (int p = 0; p < len; p++) buf[p] = (char)(1 + (xs(seed) % 255));
    buf[len] = '\0';
    key_check(buf, len);
}

/* Byte position at which an absent key should first differ from a stored one. */
static int absent_depth(int mode, int len) {
    switch (mode) {
        case DIVERGE_SHALLOW: return 0;
        case DIVERGE_MID:     return len / 2;
        case DIVERGE_DEEP:    return len >= 2 ? len - 2 : 0;
        default:              return len - 1;          /* DIVERGE_LAST */
    }
}

/* Copy a stored key and change exactly one byte, at `depth`, keeping the
 * length. The result is absent (checked against the tree) and shares a
 * `depth`-byte prefix with a stored key.
 *
 * Divergence depth is the independent variable the miss comparison needs. A
 * trie fails at the first differing byte, so its miss cost tracks `depth`; a
 * hash digests all `len` bytes whatever `depth` is. Sweeping `depth` at fixed
 * length turns "the trie wins on a miss" from one favourable point into a
 * curve, and the point DIVERGE_OFFSET happens to land on for long keys is the
 * most favourable one there is (byte 5 of 16).
 *
 * The realised divergence is *at least* `depth`, not exactly it: the mutated
 * key shares `depth` bytes with its source, but another stored key may share
 * more. Judy exposes no way to read back the longest common prefix it actually
 * walked, so this is a lower bound on depth, not a measured one. */
static int make_divergent_key(char *dst, const char *src, int depth,
                              int corpus, Pvoid_t sl, unsigned long *seed) {
    size_t len = strlen(src);
    PWord_t chk;

    if (depth < 0 || (size_t)depth >= len) return -1;
    memcpy(dst, src, len + 1);
    for (int t = 0; t < 512; t++) {
        /* Prefer a replacement from the corpus's own alphabet, so the absent
         * keys stay the same shape as the present ones; fall back to arbitrary
         * bytes only if every in-alphabet candidate is already stored. */
        char c = (corpus == CORPUS_STRUCT && t < KEY_RADIX)
                     ? key_alphabet[t]
                     : (char)(1 + (xs(seed) % 255));
        if (c == src[depth]) continue;
        dst[depth] = c;
        JSLG(chk, sl, (uint8_t *)dst);
        if (chk == NULL) return 0;
    }
    return -1;
}

int main(int argc, char **argv) {
    unsigned long n = (argc > 1) ? strtoul(argv[1], NULL, 10) : 1000000;
    int keylen = (argc > 2) ? atoi(argv[2]) : 16;
    int reps = (argc > 3) ? atoi(argv[3]) : 5;
    int corpus = CORPUS_STRUCT;
    int diverge = DIVERGE_OFFSET;
    char key[MAXK];
    unsigned long gen_seed = 0x9e3779b97f4a7c15UL;
    unsigned long collisions = 0;
    PWord_t pv;

    if (argc > 4) {
        if      (!strcmp(argv[4], "struct")) corpus = CORPUS_STRUCT;
        else if (!strcmp(argv[4], "rand"))   corpus = CORPUS_RAND;
        else if (!strcmp(argv[4], "varlen")) corpus = CORPUS_VARLEN;
        else { fprintf(stderr, "corpus must be struct|rand|varlen\n"); return 1; }
    }
    if (argc > 5) {
        if      (!strcmp(argv[5], "offset"))  diverge = DIVERGE_OFFSET;
        else if (!strcmp(argv[5], "shallow")) diverge = DIVERGE_SHALLOW;
        else if (!strcmp(argv[5], "mid"))     diverge = DIVERGE_MID;
        else if (!strcmp(argv[5], "deep"))    diverge = DIVERGE_DEEP;
        else if (!strcmp(argv[5], "last"))    diverge = DIVERGE_LAST;
        else { fprintf(stderr, "absent must be offset|shallow|mid|deep|last\n"); return 1; }
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
     * keylen bytes, so it can only express so many distinct keys. They must
     * fit, or keys collide and every number below is meaningless. DIVERGE_OFF-
     * SET draws its absent keys from the same space, so it needs room for 2n;
     * the DIVERGE_* modes mutate a stored key instead and only need n. */
    if (corpus == CORPUS_STRUCT && keylen < LONGKEY_MIN) {
        unsigned long cap = short_key_space(keylen);
        unsigned long need = (diverge == DIVERGE_OFFSET) ? 2 * n : n;
        if (cap < need) {
            fprintf(stderr,
                "keylen %d holds only %lu distinct keys; need %lu. "
                "Raise keylen or lower n.\n", keylen, cap, need);
            return 1;
        }
    }

    /* sl  = the JudySL key_index (B3 would store the payload in its value word)
     * hs  = the JudyHS value store used by *_INT_HASH and by ADAPTIVE long keys
     * jl  = the JudyL SSO value store used by ADAPTIVE short keys (keylen < 8) */
    Pvoid_t sl = (Pvoid_t)NULL, hs = (Pvoid_t)NULL, jl = (Pvoid_t)NULL;
    int sso = (keylen < 8);

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
        if (sso) {
            Word_t idx = 0;
            size_t kl = strlen(key);
            if (kl > sizeof(Word_t)) kl = sizeof(Word_t);   /* defensive: see #118 */
            memcpy(&idx, key, kl);
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
        char *a = absent + (size_t)i * MAXK;
        const char *src = keys + (size_t)i * MAXK;

        if (diverge != DIVERGE_OFFSET) {
            int depth = absent_depth(diverge, (int)strlen(src));
            if (make_divergent_key(a, src, depth, corpus, sl, &gen_seed) != 0) {
                fprintf(stderr, "no absent key diverging at byte %d of \"%s\"\n",
                        depth, src);
                return 1;
            }
        } else if (corpus == CORPUS_STRUCT) {
            /* The historical generator, kept verbatim so every miss figure
             * published before this option existed stays reproducible. */
            make_key(a, i + (keylen >= LONGKEY_MIN ? ABSENT_OFFSET_LONG : n), keylen);
        } else {
            /* The random corpora's equivalent of an independent index range:
             * an independently drawn key, redrawn until it is absent. */
            PWord_t chk;
            int len = (int)strlen(src);
            for (;;) {
                make_random_key(a, len, &gen_seed);
                JSLG(chk, sl, (uint8_t *)a);
                if (chk == NULL) break;
            }
        }
        /* Every absent key is checked, not just a sample of them: a miss
         * benchmark that is quietly measuring hits reports nothing at all. */
        {
            PWord_t chk;
            JSLG(chk, sl, (uint8_t *)a);
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
                size_t kl;
                k = keys + (size_t)order[i] * MAXK;
                kl = strlen(k);
                if (kl > sizeof(Word_t)) kl = sizeof(Word_t);   /* defensive: see #118 */
                memcpy(&idx, k, kl);
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
            /* long, not Word_t: JSLFA/JHSFA compare the result against JERR
             * (-1), which is -Wsign-compare noise on an unsigned. */
            long freed;

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
            JSLFA(freed, s2); sink += (unsigned long)freed;
            JHSFA(freed, h2); sink += (unsigned long)freed;
        }
    }

    printf("n=%lu keylen=%d reps=%d corpus=%s absent=%s (median ns/op; min..max)\n",
           n, keylen, reps, corpus_name[corpus], diverge_name[diverge]);
    if (corpus != CORPUS_STRUCT)
        printf("  (redraws to avoid duplicate keys: %lu)\n", collisions);
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

    /* Released rather than left to exit(2) so a leak checker has something to
     * say. long, not Word_t: the J*FA macros compare against JERR (-1). */
    { long freed;
      JSLFA(freed, sl); JHSFA(freed, hs); JLFA(freed, jl); (void)freed; }
    free(keys); free(absent); free(order);
    return 0;
}
