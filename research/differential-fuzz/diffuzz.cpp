/* Differential fuzzing harness for libJudy — issue #142 Stage 4.
 *
 * Drives every Judy family the extension uses against an exact oracle from
 * the C++ standard library, through randomized-but-reproducible op sequences:
 *
 *   Judy1  vs std::set<Word_t>
 *   JudyL  vs std::map<Word_t, Word_t>
 *   JudySL vs std::map<std::string, Word_t>       (NUL-free keys)
 *   JudyHS vs std::unordered_map<std::string, Word_t>  (arbitrary bytes)
 *
 * Invariants are checked after every op batch, not only at the end:
 * membership agreement on a probe sample, full count agreement (J1C/JLC),
 * and periodically a full ordered walk compared element-by-element against
 * the oracle in both directions (First/Next from 0 and Last/Prev from ~0).
 * Judy1SetArray/JudyLInsArray bulk builds are exercised at a Count sweep
 * that includes 31, the #127 off-by-one boundary (see --no-bulk below).
 *
 * Why this exists: issue #131 — stock libJudy 1.0.5 built with gcc -O3
 * silently loses Judy1 keys (inserted, then denied by lookup, while J1C
 * over-reports). A differential oracle finds exactly that class in seconds.
 * The harness is validated-to-fail against both historical bug classes;
 * see README.md in this directory for the recorded validation runs.
 *
 * Reproducibility: the PRNG is a self-contained splitmix64 (std::
 * uniform_int_distribution is not portable across stdlibs), the seed is
 * printed on every run, and every divergence prints a self-contained
 * reproduction command line before exiting non-zero.
 *
 * Usage:
 *   ./diffuzz smoke [ops]                    fixed seeds, full grid (CI)
 *   ./diffuzz soak <seconds> [seed]          time-bounded, random seeds
 *   ./diffuzz one <domain> <corpus> <seed> [ops]   reproduce one cell
 *   ./diffuzz list                           print the domain/corpus grid
 * Flags (anywhere): --no-bulk   skip Judy1SetArray/JudyLInsArray bulk ops.
 *   Against *stock* 1.0.5 (Homebrew, SourceForge) the bulk sweep hits the
 *   #127 JudyInsArray off-by-one at Count==31: an ASan-instrumented library
 *   reports a global-buffer-overflow, a plain build silently writes 63
 *   words into a zero-word allocation (heap corruption). That is the
 *   harness working. Use --no-bulk to scope a run to the classic APIs.
 *
 * Exit status: 0 ok, 1 divergence (repro line printed), 2 usage/env error.
 */

#include <Judy.h>

#include <algorithm>
#include <cinttypes>
#include <cstdarg>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <ctime>
#include <map>
#include <set>
#include <string>
#include <unordered_map>
#include <vector>

/* ------------------------------------------------------------------ PRNG */

/* splitmix64: tiny, seedable, identical output on every platform/stdlib. */
struct Rng {
    uint64_t s;
    explicit Rng(uint64_t seed) : s(seed) {}
    uint64_t next() {
        uint64_t z = (s += 0x9E3779B97F4A7C15ULL);
        z = (z ^ (z >> 30)) * 0xBF58476D1CE4E5B9ULL;
        z = (z ^ (z >> 27)) * 0x94D049BB133111EBULL;
        return z ^ (z >> 31);
    }
    uint64_t below(uint64_t n) { return n ? next() % n : 0; }
    bool chance(uint64_t one_in) { return below(one_in) == 0; }
};

/* -------------------------------------------------------- run context */

struct RunCtx {
    const char *domain = "?";
    const char *corpus = "?";
    uint64_t seed = 0;
    uint64_t nops = 0;
    uint64_t op = 0;         /* current op index, for the failure report */
    const char *phase = "?"; /* bulk / fuzz / batch / walk / final */
    bool bulk = true;
};
static RunCtx g;

[[noreturn]] static void divergence(const char *fmt, ...) {
    fprintf(stderr,
            "\nDIVERGENCE domain=%s corpus=%s seed=0x%" PRIx64 " ops=%" PRIu64
            " op#=%" PRIu64 " phase=%s\n  ",
            g.domain, g.corpus, g.seed, g.nops, g.op, g.phase);
    va_list ap;
    va_start(ap, fmt);
    vfprintf(stderr, fmt, ap);
    va_end(ap);
    fprintf(stderr, "\nrepro: ./diffuzz one %s %s 0x%" PRIx64 " %" PRIu64 "%s\n",
            g.domain, g.corpus, g.seed, g.nops, g.bulk ? "" : " --no-bulk");
    exit(1);
}

[[noreturn]] static void fatal(const char *fmt, ...) {
    fprintf(stderr, "\nFATAL (environment, not a divergence): ");
    va_list ap;
    va_start(ap, fmt);
    vfprintf(stderr, fmt, ap);
    va_end(ap);
    fprintf(stderr, "\n");
    exit(2);
}

/* Escape a byte-string key for failure reports. */
static std::string esc(const std::string &k) {
    std::string out;
    size_t n = k.size() < 48 ? k.size() : 48;
    char tmp[8];
    for (size_t i = 0; i < n; i++) {
        unsigned char c = (unsigned char)k[i];
        if (c >= 0x20 && c < 0x7F && c != '\\') {
            out += (char)c;
        } else {
            snprintf(tmp, sizeof tmp, "\\x%02x", c);
            out += tmp;
        }
    }
    if (k.size() > n) out += "...";
    return out;
}

/* ------------------------------------------------- word-key generators */

enum WDist { W_UNIFORM, W_CLUSTERED, W_DENSE, W_FFBIAS, W_LOWENT, W_MIXED };
static const char *const wdist_name[] = {"uniform", "clustered", "dense",
                                         "ffbias",  "lowent",    "mixed"};

struct WordGen {
    Rng &rng;
    int dist;
    uint64_t n = 0; /* keys generated so far; drives the clustered shape */
    Word_t dense_base = 0;

    explicit WordGen(Rng &r, int d) : rng(r), dist(d) {}

    Word_t gen(int d) {
        switch (d) {
        case W_UNIFORM:
            return (Word_t)rng.next();
        case W_CLUSTERED:
            /* The exact #131 reproduction shape: (rnd() & 0xFF) | ((i/64)
             * << 8) — 1-byte expanses that pass through the IMMED_1_15
             * cascade transition as they fill. */
            return (Word_t)((rng.next() & 0xFF) | ((n / 64) << 8));
        case W_DENSE:
            if (dense_base == 0 || rng.chance(1024))
                dense_base = (Word_t)(rng.next() & ~(uint64_t)0xFFFF);
            return dense_base + (Word_t)rng.below(4096);
        case W_FFBIAS: { /* the ASan corpus: 0xFF-saturated bytes */
            Word_t w = 0;
            for (int i = 0; i < 8; i++) {
                w <<= 8;
                w |= (rng.next() & 1) ? 0xFF : (Word_t)(rng.next() & 0xFF);
            }
            return w;
        }
        case W_LOWENT:
            switch (rng.below(3)) {
            case 0: /* (h<<8)|l with l < 15: immediate-JP churn */
                return (Word_t)(rng.below(15) | (rng.below(64) << 8));
            case 1: /* power-of-two edges */
                return (((Word_t)1 << rng.below(64)) - (Word_t)(rng.next() & 1));
            default:
                return (Word_t)rng.below(256);
            }
        default: /* W_MIXED */
            return gen((int)rng.below(5));
        }
    }
    Word_t next() {
        Word_t w = gen(dist);
        n++;
        return w;
    }
};

/* ----------------------------------------------- string-key generators
 *
 * The struct/rand/varlen corpora are ports of make_key() from
 * research/iteration-cost/iterbench.c (post-#139), including the #122
 * truncation fix and the unconditional exact-length check. boundary and
 * ffbias add the shapes that historically hid bugs: keys crossing the
 * 8-byte SSO/word boundary (lengths 4..9 exactly) and 0xFF-biased bytes.
 */

#define MAXK 96 /* longest key any corpus emits; walk buffers add 1 */

enum SCorpus { S_STRUCT, S_RAND, S_VARLEN, S_BOUNDARY, S_FFBIAS, S_MIXED };
static const char *const scorpus_name[] = {"struct",   "rand",   "varlen",
                                           "boundary", "ffbias", "mixed"};

/* Exact-length guard, ported from iterbench.c: survives -DNDEBUG because a
 * generator that silently ignores its length argument is the historical
 * defect itself (issue #122). Generator bug => abort, not divergence. */
static void key_check(const char *buf, int want) {
    size_t got = strlen(buf);
    if ((int)got != want) {
        fprintf(stderr, "make_key: emitted %zu bytes, wanted %d (\"%s\")\n",
                got, want, buf);
        abort();
    }
}

#define LONGKEY_MIN 16
#define KEY_RADIX 36
static const char key_alphabet[] = "0123456789abcdefghijklmnopqrstuvwxyz";

static unsigned long short_key_space(int keylen) {
    unsigned long cap = 1;
    for (int p = 0; p < keylen; p++) {
        if (cap > ULONG_MAX / KEY_RADIX) return ULONG_MAX;
        cap *= KEY_RADIX;
    }
    return cap;
}

/* Golden-ratio multiplier coprime with 36^keylen — see iterbench.c for the
 * derivation (a fixed constant leaves high digits stuck at '0'). */
static unsigned long key_mix_for(unsigned long cap) {
    unsigned long m = (unsigned long)((long double)cap * 0.6180339887498949L);
    if (m < 3) return 1;
    if ((m & 1UL) == 0) m++;
    while (m % 3 == 0) m += 2;
    return m;
}

static void make_struct_key(char *buf, unsigned long i, int keylen) {
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
        v = i * mix; /* wrap mod 2^64; mix odd => bijective */
    } else {
        v = (unsigned long)(((unsigned __int128)i * mix) % cap);
    }

    for (int p = keylen - 1; p >= 0; p--) {
        buf[p] = key_alphabet[v % KEY_RADIX];
        v /= KEY_RADIX;
    }
    buf[keylen] = '\0';
    key_check(buf, keylen);
}

struct StrGen {
    Rng &rng;
    int corpus;
    explicit StrGen(Rng &r, int c) : rng(r), corpus(c) {}

    std::string gen(int c) {
        char buf[MAXK + 1];
        int len;
        switch (c) {
        case S_STRUCT: {
            static const int lens[] = {4, 6, 8, 12, 15, 16, 17, 24, 40};
            len = lens[rng.below(sizeof lens / sizeof lens[0])];
            unsigned long i;
            if (len >= LONGKEY_MIN) {
                i = rng.below(1000000000UL); /* i/10 must fit %08lu */
            } else {
                unsigned long cap = short_key_space(len);
                /* Keep the drawn space modest at short lengths so inserts
                 * and deletes actually collide. */
                if (cap > 1000000UL) cap = 1000000UL;
                i = rng.below(cap);
            }
            make_struct_key(buf, i, len);
            return std::string(buf, (size_t)len);
        }
        case S_RAND: {
            static const int lens[] = {2, 3, 4, 5, 6, 7, 8,
                                       9, 12, 16, 17, 24, 40};
            len = lens[rng.below(sizeof lens / sizeof lens[0])];
            break;
        }
        case S_VARLEN:
            len = 4 + (int)rng.below(37); /* ragged 4..40 */
            break;
        case S_BOUNDARY:
            len = 4 + (int)rng.below(6); /* 4..9 exactly: SSO/word boundary */
            break;
        case S_FFBIAS: {
            len = (int)rng.below(25); /* 0..24, includes the empty key */
            for (int p = 0; p < len; p++)
                buf[p] = (rng.next() & 1) ? (char)0xFF
                                          : (char)(1 + rng.below(255));
            return std::string(buf, (size_t)len);
        }
        default: /* S_MIXED */
            return gen((int)rng.below(5));
        }
        /* uniform bytes 1..255 — NUL excluded, JudySL keys are C strings */
        for (int p = 0; p < len; p++) buf[p] = (char)(1 + rng.below(255));
        return std::string(buf, (size_t)len);
    }
    std::string next() { return gen(corpus); }
};

/* JudyHS keys are (pointer, length): arbitrary bytes, NUL and empty legal. */
enum HCorpus { H_RAND, H_BOUNDARY, H_FFBIAS, H_SHORT, H_COLLIDE, H_MIXED };
static const char *const hcorpus_name[] = {"rand",  "boundary", "ffbias",
                                           "short", "collide",  "mixed"};

struct HashGen {
    Rng &rng;
    int corpus;
    explicit HashGen(Rng &r, int c) : rng(r), corpus(c) {}

    std::string gen(int c) {
        size_t len;
        bool ffbias = false;
        switch (c) {
        case H_RAND:     len = rng.below(41); break;
        case H_BOUNDARY: len = 4 + rng.below(6); break; /* 4..9 exactly */
        case H_FFBIAS:   len = rng.below(25); ffbias = true; break;
        case H_SHORT:    len = rng.below(4); break;     /* 0..3 */
        case H_COLLIDE: {
            /* Engineered 32-bit hash collisions: under JudyHS's
             * c' = c*31 + byte hash, the 2-byte blocks "Aa", "BB", "C#"
             * each contribute 65*31+97 = 66*31+66 = 67*31+35 = 2112, so
             * every key with the same block count shares its full hash
             * AND its length -- all land in one hash bucket, driving the
             * collision subtree that random keys essentially never reach:
             * ls_t splits on insert, word-tree descends, leaf compares
             * and structural misses on get/delete. Block counts 5..9
             * give lengths 10..18 (> WORDSIZE, so the hash path is
             * active, spanning the 16-byte word boundary). Added for the
             * O4d JudyHSDel gate (#142): the folded-in presence check --
             * leaf memcmp and null-branch guards -- only fires under
             * colliding deletes. */
            static const char *const blk[3] = {"Aa", "BB", "C#"};
            size_t nb = 5 + rng.below(5);
            std::string k;
            k.reserve(nb * 2);
            for (size_t b = 0; b < nb; b++) k += blk[rng.below(3)];
            return k;
        }
        default:         return gen((int)rng.below(5)); /* H_MIXED */
        }
        std::string k(len, '\0');
        for (size_t p = 0; p < len; p++) {
            if (ffbias && (rng.next() & 1))
                k[p] = (char)0xFF;
            else
                k[p] = (char)rng.below(256); /* NUL bytes included */
        }
        return k;
    }
    std::string next() { return gen(corpus); }
};

/* --------------------------------------------------------- Judy helpers */

static PWord_t chk_slot(PPvoid_t p, const char *api) {
    if (p == PPJERR) fatal("%s returned PPJERR (malloc failure?)", api);
    return (PWord_t)p;
}

/* =================================================================== Judy1 */

static Word_t set_member_near(const std::set<Word_t> &o, Word_t k) {
    auto it = o.lower_bound(k);
    if (it == o.end()) it = o.begin();
    return *it;
}

static void judy1_check_walk(Pvoid_t j, const std::set<Word_t> &o) {
    Word_t idx = 0;
    int rc = Judy1First(j, &idx, PJE0);
    uint64_t pos = 0;
    for (auto it = o.begin();; ++it, ++pos) {
        bool oh = (it != o.end());
        if (rc != 1 && oh)
            divergence("forward walk pos %" PRIu64
                       ": Judy1 ended, oracle still has 0x%lx (oracle size %zu)",
                       pos, *it, o.size());
        if (rc == 1 && !oh)
            divergence("forward walk pos %" PRIu64
                       ": oracle ended, Judy1 still has 0x%lx",
                       pos, idx);
        if (!oh) break;
        if (idx != *it)
            divergence("forward walk pos %" PRIu64
                       ": Judy1 key 0x%lx != oracle key 0x%lx",
                       pos, idx, *it);
        rc = Judy1Next(j, &idx, PJE0);
    }
    idx = ~(Word_t)0;
    rc = Judy1Last(j, &idx, PJE0);
    pos = 0;
    for (auto it = o.rbegin();; ++it, ++pos) {
        bool oh = (it != o.rend());
        if (rc != 1 && oh)
            divergence("backward walk pos %" PRIu64
                       ": Judy1 ended, oracle still has 0x%lx",
                       pos, *it);
        if (rc == 1 && !oh)
            divergence("backward walk pos %" PRIu64
                       ": oracle ended, Judy1 still has 0x%lx",
                       pos, idx);
        if (!oh) break;
        if (idx != *it)
            divergence("backward walk pos %" PRIu64
                       ": Judy1 key 0x%lx != oracle key 0x%lx",
                       pos, idx, *it);
        rc = Judy1Prev(j, &idx, PJE0);
    }
}

static void judy1_cmp_search(const char *api, Word_t from, int rc, Word_t got,
                             bool epresent, Word_t ekey) {
    if (rc != (epresent ? 1 : 0) || (rc == 1 && got != ekey))
        divergence("%s from 0x%lx: judy rc=%d idx=0x%lx; oracle expects %s%s0x%lx",
                   api, from, rc, got, epresent ? "hit " : "no key",
                   epresent ? "" : " ", epresent ? ekey : (Word_t)0);
}

static void judy1_neighbors(Pvoid_t j, const std::set<Word_t> &o, Word_t k) {
    Word_t idx;
    int rc;
    { /* J1F: first key >= k */
        idx = k;
        rc = Judy1First(j, &idx, PJE0);
        auto it = o.lower_bound(k);
        judy1_cmp_search("J1F", k, rc, idx, it != o.end(),
                         it != o.end() ? *it : 0);
    }
    { /* J1N: first key > k */
        idx = k;
        rc = Judy1Next(j, &idx, PJE0);
        auto it = o.upper_bound(k);
        judy1_cmp_search("J1N", k, rc, idx, it != o.end(),
                         it != o.end() ? *it : 0);
    }
    { /* J1L: last key <= k */
        idx = k;
        rc = Judy1Last(j, &idx, PJE0);
        auto it = o.upper_bound(k);
        bool p = (it != o.begin());
        judy1_cmp_search("J1L", k, rc, idx, p, p ? *std::prev(it) : 0);
    }
    { /* J1P: last key < k */
        idx = k;
        rc = Judy1Prev(j, &idx, PJE0);
        auto it = o.lower_bound(k);
        bool p = (it != o.begin());
        judy1_cmp_search("J1P", k, rc, idx, p, p ? *std::prev(it) : 0);
    }
}

static void judy1_batch(Pvoid_t j, const std::set<Word_t> &o, Rng &rng,
                        WordGen &gen) {
    Word_t cnt = Judy1Count(j, 0, ~(Word_t)0, PJE0);
    if (cnt != (Word_t)o.size())
        divergence("full count: J1C=%lu oracle=%zu", cnt, o.size());
    for (int i = 0; i < 16; i++) {
        if (!o.empty()) {
            Word_t m = set_member_near(o, gen.next());
            if (Judy1Test(j, m, PJE0) != 1)
                divergence("membership: key 0x%lx in oracle, J1T says absent", m);
        }
        Word_t k = gen.next();
        int e = o.count(k) ? 1 : 0;
        int rc = Judy1Test(j, k, PJE0);
        if (rc != e)
            divergence("probe: J1T(0x%lx)=%d oracle=%d", k, rc, e);
    }
    for (int i = 0; i < 4; i++) { /* range count */
        Word_t a = gen.next();
        Word_t b = a + rng.below(1 << 20);
        if (b < a) b = ~(Word_t)0;
        Word_t got = Judy1Count(j, a, b, PJE0);
        Word_t want = 0;
        for (auto it = o.lower_bound(a); it != o.end() && *it <= b; ++it)
            want++;
        if (got != want)
            divergence("range count [0x%lx, 0x%lx]: J1C=%lu oracle=%lu", a, b,
                       got, want);
    }
}

static void judy1_bulk(Rng &rng, WordGen &gen) {
    static const Word_t counts[] = {1,  2,  3,  7,  8,   15,  16,  17,  23,
                                    24, 30, 31, 32, 33,  63,  64,  100, 256,
                                    1000};
    g.phase = "bulk";
    (void)rng;
    for (Word_t c : counts) {
        std::set<Word_t> o;
        while (o.size() < c) o.insert(gen.next());
        std::vector<Word_t> keys(o.begin(), o.end());
        Pvoid_t j = NULL;
        int rc = Judy1SetArray(&j, c, keys.data(), PJE0);
        if (rc != 1) fatal("Judy1SetArray(Count=%lu) rc=%d", c, rc);
        Word_t cnt = Judy1Count(j, 0, ~(Word_t)0, PJE0);
        if (cnt != c)
            divergence("bulk Count=%lu: J1C=%lu after Judy1SetArray", c, cnt);
        judy1_check_walk(j, o);
        Word_t bytes = Judy1FreeArray(&j, PJE0);
        if (bytes == 0)
            divergence("bulk Count=%lu: J1FA freed 0 bytes", c);
        if (j != NULL) divergence("bulk Count=%lu: J1FA left array non-NULL", c);
    }
}

static void run_judy1(int dist) {
    Rng rng(g.seed);
    WordGen gen(rng, dist);
    if (g.bulk) judy1_bulk(rng, gen);

    Pvoid_t j = NULL;
    std::set<Word_t> o;
    for (g.op = 0; g.op < g.nops; g.op++) {
        g.phase = "fuzz";
        uint64_t r = rng.below(100);
        Word_t k = gen.next();
        if (r < 40) {
            int rc = Judy1Set(&j, k, PJE0);
            if (rc == JERR) fatal("Judy1Set JERR");
            int e = o.insert(k).second ? 1 : 0;
            if (rc != e) divergence("J1S(0x%lx): rc=%d oracle expects %d", k, rc, e);
        } else if (r < 60) {
            if (r < 50 && !o.empty()) k = set_member_near(o, k);
            int rc = Judy1Unset(&j, k, PJE0);
            if (rc == JERR) fatal("Judy1Unset JERR");
            int e = (int)o.erase(k);
            if (rc != e) divergence("J1U(0x%lx): rc=%d oracle expects %d", k, rc, e);
        } else if (r < 78) {
            if (r < 69 && !o.empty()) k = set_member_near(o, k);
            int rc = Judy1Test(j, k, PJE0);
            int e = o.count(k) ? 1 : 0;
            if (rc != e) divergence("J1T(0x%lx): rc=%d oracle expects %d", k, rc, e);
        } else if (r < 90) {
            judy1_neighbors(j, o, k);
        } else {
            Word_t a = k, b = k + rng.below(1 << 16);
            if (b < a) b = ~(Word_t)0;
            Word_t got = Judy1Count(j, a, b, PJE0);
            Word_t want = 0;
            for (auto it = o.lower_bound(a); it != o.end() && *it <= b; ++it)
                want++;
            if (got != want)
                divergence("range count [0x%lx, 0x%lx]: J1C=%lu oracle=%lu",
                           a, b, got, want);
        }
        if (rng.chance(8192)) {
            g.phase = "freeall";
            Word_t bytes = Judy1FreeArray(&j, PJE0);
            if (!o.empty() && bytes == 0)
                divergence("J1FA freed 0 bytes with %zu keys stored", o.size());
            if (j != NULL) divergence("J1FA left the array pointer non-NULL");
            o.clear();
        }
        if ((g.op + 1) % 256 == 0) {
            g.phase = "batch";
            judy1_batch(j, o, rng, gen);
        }
        if ((g.op + 1) % 4096 == 0) {
            g.phase = "walk";
            judy1_check_walk(j, o);
        }
    }
    g.phase = "final";
    judy1_batch(j, o, rng, gen);
    judy1_check_walk(j, o);
    Judy1FreeArray(&j, PJE0);
}

/* =================================================================== JudyL */

static Word_t map_member_near(const std::map<Word_t, Word_t> &o, Word_t k) {
    auto it = o.lower_bound(k);
    if (it == o.end()) it = o.begin();
    return it->first;
}

static void judyl_check_walk(Pvoid_t j, const std::map<Word_t, Word_t> &o) {
    Word_t idx = 0;
    PWord_t pv = chk_slot(JudyLFirst(j, &idx, PJE0), "JudyLFirst");
    uint64_t pos = 0;
    for (auto it = o.begin();; ++it, ++pos) {
        bool oh = (it != o.end());
        if (pv == NULL && oh)
            divergence("forward walk pos %" PRIu64
                       ": JudyL ended, oracle still has 0x%lx",
                       pos, it->first);
        if (pv != NULL && !oh)
            divergence("forward walk pos %" PRIu64
                       ": oracle ended, JudyL still has 0x%lx",
                       pos, idx);
        if (!oh) break;
        if (idx != it->first)
            divergence("forward walk pos %" PRIu64
                       ": JudyL key 0x%lx != oracle key 0x%lx",
                       pos, idx, it->first);
        if (*pv != it->second)
            divergence("forward walk pos %" PRIu64
                       ": key 0x%lx value 0x%lx != oracle 0x%lx",
                       pos, idx, *pv, it->second);
        pv = chk_slot(JudyLNext(j, &idx, PJE0), "JudyLNext");
    }
    idx = ~(Word_t)0;
    pv = chk_slot(JudyLLast(j, &idx, PJE0), "JudyLLast");
    pos = 0;
    for (auto it = o.rbegin();; ++it, ++pos) {
        bool oh = (it != o.rend());
        if (pv == NULL && oh)
            divergence("backward walk pos %" PRIu64
                       ": JudyL ended, oracle still has 0x%lx",
                       pos, it->first);
        if (pv != NULL && !oh)
            divergence("backward walk pos %" PRIu64
                       ": oracle ended, JudyL still has 0x%lx",
                       pos, idx);
        if (!oh) break;
        if (idx != it->first)
            divergence("backward walk pos %" PRIu64
                       ": JudyL key 0x%lx != oracle key 0x%lx",
                       pos, idx, it->first);
        if (*pv != it->second)
            divergence("backward walk pos %" PRIu64
                       ": key 0x%lx value 0x%lx != oracle 0x%lx",
                       pos, idx, *pv, it->second);
        pv = chk_slot(JudyLPrev(j, &idx, PJE0), "JudyLPrev");
    }
}

static void judyl_cmp_search(const char *api, Word_t from, PWord_t pv,
                             Word_t got, const std::map<Word_t, Word_t> &o,
                             std::map<Word_t, Word_t>::const_iterator eit) {
    bool ep = (eit != o.end());
    if ((pv != NULL) != ep || (pv && got != eit->first))
        divergence("%s from 0x%lx: judy %s 0x%lx; oracle expects %s 0x%lx", api,
                   from, pv ? "hit" : "miss", pv ? got : 0,
                   ep ? "hit" : "miss", ep ? eit->first : 0);
    if (pv && *pv != eit->second)
        divergence("%s from 0x%lx: key 0x%lx value 0x%lx != oracle 0x%lx", api,
                   from, got, *pv, eit->second);
}

static void judyl_neighbors(Pvoid_t j, const std::map<Word_t, Word_t> &o,
                            Word_t k) {
    Word_t idx;
    PWord_t pv;
    {
        idx = k;
        pv = chk_slot(JudyLFirst(j, &idx, PJE0), "JudyLFirst");
        judyl_cmp_search("JLF", k, pv, idx, o, o.lower_bound(k));
    }
    {
        idx = k;
        pv = chk_slot(JudyLNext(j, &idx, PJE0), "JudyLNext");
        judyl_cmp_search("JLN", k, pv, idx, o, o.upper_bound(k));
    }
    {
        idx = k;
        pv = chk_slot(JudyLLast(j, &idx, PJE0), "JudyLLast");
        auto it = o.upper_bound(k);
        judyl_cmp_search("JLL", k, pv, idx, o,
                         it == o.begin() ? o.end() : std::prev(it));
    }
    {
        idx = k;
        pv = chk_slot(JudyLPrev(j, &idx, PJE0), "JudyLPrev");
        auto it = o.lower_bound(k);
        judyl_cmp_search("JLP", k, pv, idx, o,
                         it == o.begin() ? o.end() : std::prev(it));
    }
}

static void judyl_batch(Pvoid_t j, const std::map<Word_t, Word_t> &o, Rng &rng,
                        WordGen &gen) {
    Word_t cnt = JudyLCount(j, 0, ~(Word_t)0, PJE0);
    if (cnt != (Word_t)o.size())
        divergence("full count: JLC=%lu oracle=%zu", cnt, o.size());
    for (int i = 0; i < 16; i++) {
        if (!o.empty()) {
            Word_t m = map_member_near(o, gen.next());
            PWord_t pv = chk_slot(JudyLGet(j, m, PJE0), "JudyLGet");
            if (pv == NULL)
                divergence("membership: key 0x%lx in oracle, JLG says absent", m);
            if (*pv != o.at(m))
                divergence("membership: key 0x%lx value 0x%lx != oracle 0x%lx",
                           m, *pv, o.at(m));
        }
        Word_t k = gen.next();
        PWord_t pv = chk_slot(JudyLGet(j, k, PJE0), "JudyLGet");
        auto it = o.find(k);
        if ((pv != NULL) != (it != o.end()))
            divergence("probe: JLG(0x%lx)=%s oracle=%s", k,
                       pv ? "hit" : "miss", it != o.end() ? "hit" : "miss");
        if (pv && *pv != it->second)
            divergence("probe: JLG(0x%lx) value 0x%lx != oracle 0x%lx", k, *pv,
                       it->second);
    }
    for (int i = 0; i < 4; i++) {
        Word_t a = gen.next();
        Word_t b = a + rng.below(1 << 20);
        if (b < a) b = ~(Word_t)0;
        Word_t got = JudyLCount(j, a, b, PJE0);
        Word_t want = 0;
        for (auto it = o.lower_bound(a); it != o.end() && it->first <= b; ++it)
            want++;
        if (got != want)
            divergence("range count [0x%lx, 0x%lx]: JLC=%lu oracle=%lu", a, b,
                       got, want);
    }
}

#ifdef HAVE_JUDYL_MULTIGET
/* JudyLMultiGet() is a bundled-libJudy addition (#142 patch O5), absent
 * from stock/system builds -- this mode compiles only when the Makefile is
 * told the library under test has it (make MULTIGET=1).  The declaration
 * is repeated here rather than including libjudy/src/JudyMultiGet.h so the
 * harness keeps building against a bare install prefix. */
extern "C" Word_t JudyLMultiGet(Pcvoid_t PArray, const Word_t *PIndex,
                                PPvoid_t *PPValue, Word_t Count);

/* Batched-vs-serial oracle: JudyLMultiGet's answer for every slot must be
 * POINTER-identical to JudyLGet's, and the hit count must match.  Batch
 * sizes sweep the lane-starvation edges (empty, 1, below/at/above the
 * 16-lane default, and beyond); composition mixes stored keys, +1 near-
 * misses, duplicates within the batch, and generator keys, with
 * occasional all-hits / all-misses batches.  Slots are pre-poisoned so an
 * unwritten slot cannot masquerade as a correct miss. */
static void judyl_multiget(Pvoid_t j, const std::map<Word_t, Word_t> &o,
                           Rng &rng, WordGen &gen) {
    static const size_t sizes[] = {0, 1, 2,  3,  7,  15, 16,
                                   17, 31, 32, 33, 64, 100, 256};
    static int poison;
    size_t c = sizes[rng.below(sizeof sizes / sizeof sizes[0])];
    uint64_t comp = rng.below(8); /* 0: all stored, 1: all generator, else mixed */
    std::vector<Word_t> keys;
    keys.reserve(c);
    for (size_t i = 0; i < c; i++) {
        uint64_t r = rng.below(100);
        if (comp == 0 && !o.empty()) {
            keys.push_back(map_member_near(o, gen.next()));
        } else if (comp == 1) {
            keys.push_back(gen.next());
        } else if (r < 35 && !o.empty()) {
            keys.push_back(map_member_near(o, gen.next()));    /* hit */
        } else if (r < 55 && !o.empty()) {
            keys.push_back(map_member_near(o, gen.next()) + 1); /* near miss */
        } else if (r < 70 && !keys.empty()) {
            keys.push_back(keys[rng.below(keys.size())]);      /* duplicate */
        } else {
            keys.push_back(gen.next());
        }
    }
    std::vector<PPvoid_t> vals(c, (PPvoid_t)&poison);
    Word_t hits = JudyLMultiGet(j, keys.data(), vals.data(), c);
    Word_t want_hits = 0;
    for (size_t i = 0; i < c; i++) {
        PPvoid_t pv = JudyLGet(j, keys[i], PJE0);
        if (vals[i] != pv)
            divergence("multiget[%zu/%zu] key 0x%lx: batched %p != serial %p",
                       i, c, keys[i], (void *)vals[i], (void *)pv);
        if (pv != NULL && pv != PPJERR) want_hits++;
    }
    if (hits != want_hits)
        divergence("multiget Count=%zu: returned hits %lu != serial %lu", c,
                   hits, want_hits);
}
#endif /* HAVE_JUDYL_MULTIGET */

static void judyl_bulk(Rng &rng, WordGen &gen) {
    static const Word_t counts[] = {1,  2,  3,  7,  8,   15,  16,  17,  23,
                                    24, 30, 31, 32, 33,  63,  64,  100, 256,
                                    1000};
    g.phase = "bulk";
    for (Word_t c : counts) {
        std::map<Word_t, Word_t> o;
        while (o.size() < c) o.emplace(gen.next(), (Word_t)rng.next());
        std::vector<Word_t> keys, vals;
        keys.reserve(c);
        vals.reserve(c);
        for (auto &kv : o) {
            keys.push_back(kv.first);
            vals.push_back(kv.second);
        }
        Pvoid_t j = NULL;
        int rc = JudyLInsArray(&j, c, keys.data(), vals.data(), PJE0);
        if (rc != 1) fatal("JudyLInsArray(Count=%lu) rc=%d", c, rc);
        Word_t cnt = JudyLCount(j, 0, ~(Word_t)0, PJE0);
        if (cnt != c)
            divergence("bulk Count=%lu: JLC=%lu after JudyLInsArray", c, cnt);
        judyl_check_walk(j, o);
        for (auto &kv : o) { /* per-key point lookups on top of the walk */
            PWord_t pv = chk_slot(JudyLGet(j, kv.first, PJE0), "JudyLGet");
            if (pv == NULL || *pv != kv.second)
                divergence("bulk Count=%lu: JLG(0x%lx) %s, oracle value 0x%lx",
                           c, kv.first, pv ? "wrong value" : "miss", kv.second);
        }
        Word_t bytes = JudyLFreeArray(&j, PJE0);
        if (bytes == 0) divergence("bulk Count=%lu: JLFA freed 0 bytes", c);
        if (j != NULL) divergence("bulk Count=%lu: JLFA left array non-NULL", c);
    }
}

static void run_judyl(int dist) {
    Rng rng(g.seed);
    WordGen gen(rng, dist);
    if (g.bulk) judyl_bulk(rng, gen);

    Pvoid_t j = NULL;
    std::map<Word_t, Word_t> o;
    for (g.op = 0; g.op < g.nops; g.op++) {
        g.phase = "fuzz";
        uint64_t r = rng.below(100);
        Word_t k = gen.next();
        if (r < 40) {
            PWord_t pv = (PWord_t)JudyLIns(&j, k, PJE0);
            if (pv == NULL || pv == (PWord_t)PPJERR) fatal("JudyLIns failed");
            auto it = o.find(k);
            if (it == o.end()) {
                if (*pv != 0)
                    divergence("JLI(0x%lx): new slot not zero-initialized "
                               "(0x%lx)", k, *pv);
            } else if (*pv != it->second) {
                divergence("JLI(0x%lx): existing slot 0x%lx != oracle 0x%lx",
                           k, *pv, it->second);
            }
            Word_t val = (Word_t)rng.next();
            *pv = val;
            o[k] = val;
        } else if (r < 60) {
            if (r < 50 && !o.empty()) k = map_member_near(o, k);
            int rc = JudyLDel(&j, k, PJE0);
            if (rc == JERR) fatal("JudyLDel JERR");
            int e = (int)o.erase(k);
            if (rc != e) divergence("JLD(0x%lx): rc=%d oracle expects %d", k, rc, e);
        } else if (r < 78) {
            if (r < 69 && !o.empty()) k = map_member_near(o, k);
            PWord_t pv = chk_slot(JudyLGet(j, k, PJE0), "JudyLGet");
            auto it = o.find(k);
            if ((pv != NULL) != (it != o.end()))
                divergence("JLG(0x%lx): %s, oracle says %s", k,
                           pv ? "hit" : "miss", it != o.end() ? "hit" : "miss");
            if (pv && *pv != it->second)
                divergence("JLG(0x%lx): value 0x%lx != oracle 0x%lx", k, *pv,
                           it->second);
        } else if (r < 90) {
            judyl_neighbors(j, o, k);
        } else {
            Word_t a = k, b = k + rng.below(1 << 16);
            if (b < a) b = ~(Word_t)0;
            Word_t got = JudyLCount(j, a, b, PJE0);
            Word_t want = 0;
            for (auto it = o.lower_bound(a); it != o.end() && it->first <= b;
                 ++it)
                want++;
            if (got != want)
                divergence("range count [0x%lx, 0x%lx]: JLC=%lu oracle=%lu",
                           a, b, got, want);
        }
        if (rng.chance(8192)) {
            g.phase = "freeall";
            Word_t bytes = JudyLFreeArray(&j, PJE0);
            if (!o.empty() && bytes == 0)
                divergence("JLFA freed 0 bytes with %zu keys stored", o.size());
            if (j != NULL) divergence("JLFA left the array pointer non-NULL");
            o.clear();
        }
        if ((g.op + 1) % 256 == 0) {
            g.phase = "batch";
            judyl_batch(j, o, rng, gen);
#ifdef HAVE_JUDYL_MULTIGET
            g.phase = "multiget";
            judyl_multiget(j, o, rng, gen);
#endif
        }
        if ((g.op + 1) % 4096 == 0) {
            g.phase = "walk";
            judyl_check_walk(j, o);
        }
    }
    g.phase = "final";
    judyl_batch(j, o, rng, gen);
#ifdef HAVE_JUDYL_MULTIGET
    g.phase = "multiget";
    judyl_multiget(j, o, rng, gen);
#endif
    judyl_check_walk(j, o);
    JudyLFreeArray(&j, PJE0);
}

/* ================================================================== JudySL */

typedef std::map<std::string, Word_t> SlOracle;

/* Returned by value: gcc's -Wdangling-reference cannot see that the
 * reference would point into the map, not at the temporary argument. */
static std::string sl_member_near(const SlOracle &o, const std::string &k) {
    auto it = o.lower_bound(k);
    if (it == o.end()) it = o.begin();
    return it->first;
}

static void judysl_check_walk(Pvoid_t j, const SlOracle &o) {
    uint8_t buf[MAXK + 1];
    buf[0] = '\0';
    PWord_t pv = chk_slot(JudySLFirst(j, buf, PJE0), "JudySLFirst");
    uint64_t pos = 0;
    for (auto it = o.begin();; ++it, ++pos) {
        bool oh = (it != o.end());
        if (pv == NULL && oh)
            divergence("forward walk pos %" PRIu64
                       ": JudySL ended, oracle still has \"%s\"",
                       pos, esc(it->first).c_str());
        if (pv != NULL && !oh)
            divergence("forward walk pos %" PRIu64
                       ": oracle ended, JudySL still has \"%s\"",
                       pos, esc((const char *)buf).c_str());
        if (!oh) break;
        if (it->first != (const char *)buf)
            divergence("forward walk pos %" PRIu64
                       ": JudySL key \"%s\" != oracle key \"%s\"",
                       pos, esc((const char *)buf).c_str(),
                       esc(it->first).c_str());
        if (*pv != it->second)
            divergence("forward walk pos %" PRIu64
                       ": key \"%s\" value 0x%lx != oracle 0x%lx",
                       pos, esc(it->first).c_str(), *pv, it->second);
        pv = chk_slot(JudySLNext(j, buf, PJE0), "JudySLNext");
    }
    memset(buf, 0xFF, MAXK);
    buf[MAXK] = '\0';
    pv = chk_slot(JudySLLast(j, buf, PJE0), "JudySLLast");
    pos = 0;
    for (auto it = o.rbegin();; ++it, ++pos) {
        bool oh = (it != o.rend());
        if (pv == NULL && oh)
            divergence("backward walk pos %" PRIu64
                       ": JudySL ended, oracle still has \"%s\"",
                       pos, esc(it->first).c_str());
        if (pv != NULL && !oh)
            divergence("backward walk pos %" PRIu64
                       ": oracle ended, JudySL still has \"%s\"",
                       pos, esc((const char *)buf).c_str());
        if (!oh) break;
        if (it->first != (const char *)buf)
            divergence("backward walk pos %" PRIu64
                       ": JudySL key \"%s\" != oracle key \"%s\"",
                       pos, esc((const char *)buf).c_str(),
                       esc(it->first).c_str());
        if (*pv != it->second)
            divergence("backward walk pos %" PRIu64
                       ": key \"%s\" value 0x%lx != oracle 0x%lx",
                       pos, esc(it->first).c_str(), *pv, it->second);
        pv = chk_slot(JudySLPrev(j, buf, PJE0), "JudySLPrev");
    }
}

static void judysl_cmp_search(const char *api, const std::string &from,
                              PWord_t pv, const uint8_t *got, const SlOracle &o,
                              SlOracle::const_iterator eit) {
    bool ep = (eit != o.end());
    if ((pv != NULL) != ep ||
        (pv && eit->first != (const char *)got))
        divergence("%s from \"%s\": judy %s \"%s\"; oracle expects %s \"%s\"",
                   api, esc(from).c_str(), pv ? "hit" : "miss",
                   pv ? esc((const char *)got).c_str() : "-",
                   ep ? "hit" : "miss", ep ? esc(eit->first).c_str() : "-");
    if (pv && *pv != eit->second)
        divergence("%s from \"%s\": value 0x%lx != oracle 0x%lx", api,
                   esc(from).c_str(), *pv, eit->second);
}

static void judysl_neighbors(Pvoid_t j, const SlOracle &o,
                             const std::string &k) {
    uint8_t buf[MAXK + 1];
    PWord_t pv;
    {
        memcpy(buf, k.c_str(), k.size() + 1);
        pv = chk_slot(JudySLFirst(j, buf, PJE0), "JudySLFirst");
        judysl_cmp_search("JSLF", k, pv, buf, o, o.lower_bound(k));
    }
    {
        memcpy(buf, k.c_str(), k.size() + 1);
        pv = chk_slot(JudySLNext(j, buf, PJE0), "JudySLNext");
        judysl_cmp_search("JSLN", k, pv, buf, o, o.upper_bound(k));
    }
    {
        memcpy(buf, k.c_str(), k.size() + 1);
        pv = chk_slot(JudySLLast(j, buf, PJE0), "JudySLLast");
        auto it = o.upper_bound(k);
        judysl_cmp_search("JSLL", k, pv, buf, o,
                          it == o.begin() ? o.end() : std::prev(it));
    }
    {
        memcpy(buf, k.c_str(), k.size() + 1);
        pv = chk_slot(JudySLPrev(j, buf, PJE0), "JudySLPrev");
        auto it = o.lower_bound(k);
        judysl_cmp_search("JSLP", k, pv, buf, o,
                          it == o.begin() ? o.end() : std::prev(it));
    }
}

static void judysl_batch(Pvoid_t j, const SlOracle &o, StrGen &gen) {
    for (int i = 0; i < 16; i++) {
        if (!o.empty()) {
            std::string m = sl_member_near(o, gen.next());
            PWord_t pv = chk_slot(
                JudySLGet(j, (const uint8_t *)m.c_str(), PJE0), "JudySLGet");
            if (pv == NULL)
                divergence("membership: key \"%s\" in oracle, JSLG says absent",
                           esc(m).c_str());
            if (*pv != o.at(m))
                divergence("membership: key \"%s\" value 0x%lx != oracle 0x%lx",
                           esc(m).c_str(), *pv, o.at(m));
        }
        std::string k = gen.next();
        PWord_t pv =
            chk_slot(JudySLGet(j, (const uint8_t *)k.c_str(), PJE0), "JudySLGet");
        auto it = o.find(k);
        if ((pv != NULL) != (it != o.end()))
            divergence("probe: JSLG(\"%s\")=%s oracle=%s", esc(k).c_str(),
                       pv ? "hit" : "miss", it != o.end() ? "hit" : "miss");
        if (pv && *pv != it->second)
            divergence("probe: JSLG(\"%s\") value 0x%lx != oracle 0x%lx",
                       esc(k).c_str(), *pv, it->second);
    }
}

static void run_judysl(int corpus) {
    Rng rng(g.seed);
    StrGen gen(rng, corpus);

    Pvoid_t j = NULL;
    SlOracle o;
    for (g.op = 0; g.op < g.nops; g.op++) {
        g.phase = "fuzz";
        uint64_t r = rng.below(100);
        std::string k = gen.next();
        if (r < 40) {
            PWord_t pv =
                (PWord_t)JudySLIns(&j, (const uint8_t *)k.c_str(), PJE0);
            if (pv == NULL || pv == (PWord_t)PPJERR) fatal("JudySLIns failed");
            auto it = o.find(k);
            if (it == o.end()) {
                if (*pv != 0)
                    divergence("JSLI(\"%s\"): new slot not zero-initialized "
                               "(0x%lx)", esc(k).c_str(), *pv);
            } else if (*pv != it->second) {
                divergence("JSLI(\"%s\"): existing slot 0x%lx != oracle 0x%lx",
                           esc(k).c_str(), *pv, it->second);
            }
            Word_t val = (Word_t)rng.next();
            *pv = val;
            o[k] = val;
        } else if (r < 60) {
            if (r < 50 && !o.empty()) k = sl_member_near(o, k);
            int rc = JudySLDel(&j, (const uint8_t *)k.c_str(), PJE0);
            if (rc == JERR) fatal("JudySLDel JERR");
            int e = (int)o.erase(k);
            if (rc != e)
                divergence("JSLD(\"%s\"): rc=%d oracle expects %d",
                           esc(k).c_str(), rc, e);
        } else if (r < 80) {
            if (r < 70 && !o.empty()) k = sl_member_near(o, k);
            PWord_t pv = chk_slot(JudySLGet(j, (const uint8_t *)k.c_str(), PJE0),
                                  "JudySLGet");
            auto it = o.find(k);
            if ((pv != NULL) != (it != o.end()))
                divergence("JSLG(\"%s\"): %s, oracle says %s", esc(k).c_str(),
                           pv ? "hit" : "miss",
                           it != o.end() ? "hit" : "miss");
            if (pv && *pv != it->second)
                divergence("JSLG(\"%s\"): value 0x%lx != oracle 0x%lx",
                           esc(k).c_str(), *pv, it->second);
        } else {
            judysl_neighbors(j, o, k);
        }
        if (rng.chance(8192)) {
            g.phase = "freeall";
            Word_t bytes = JudySLFreeArray(&j, PJE0);
            if (!o.empty() && bytes == 0)
                divergence("JSLFA freed 0 bytes with %zu keys stored", o.size());
            if (j != NULL) divergence("JSLFA left the array pointer non-NULL");
            o.clear();
        }
        if ((g.op + 1) % 256 == 0) {
            g.phase = "batch";
            judysl_batch(j, o, gen);
        }
        if ((g.op + 1) % 4096 == 0) {
            g.phase = "walk";
            judysl_check_walk(j, o);
        }
    }
    g.phase = "final";
    judysl_batch(j, o, gen);
    judysl_check_walk(j, o);
    JudySLFreeArray(&j, PJE0);
}

/* ================================================================== JudyHS */

typedef std::unordered_map<std::string, Word_t> HsOracle;

static void judyhs_verify_all(Pvoid_t j, const HsOracle &o) {
    for (auto &kv : o) {
        PWord_t pv = chk_slot(
            JudyHSGet(j, (void *)kv.first.data(), (Word_t)kv.first.size()),
            "JudyHSGet");
        if (pv == NULL)
            divergence("verify-all: key \"%s\" (len %zu) in oracle, JHSG says "
                       "absent", esc(kv.first).c_str(), kv.first.size());
        if (*pv != kv.second)
            divergence("verify-all: key \"%s\" value 0x%lx != oracle 0x%lx",
                       esc(kv.first).c_str(), *pv, kv.second);
    }
}

static void run_judyhs(int corpus) {
    Rng rng(g.seed);
    HashGen gen(rng, corpus);

    Pvoid_t j = NULL;
    HsOracle o;
    for (g.op = 0; g.op < g.nops; g.op++) {
        g.phase = "fuzz";
        uint64_t r = rng.below(100);
        std::string k = gen.next();
        /* bias half the mutation ops toward keys that are actually stored */
        if (r >= 40 && r < 70 && !o.empty() && (rng.next() & 1)) {
            /* deterministic "some member": nothing ordered exists in an
             * unordered_map, so take the first of a bounded scan */
            size_t skip = rng.below(o.size() < 8 ? o.size() : 8);
            auto it = o.begin();
            while (skip--) ++it;
            k = it->first;
        }
        if (r < 40) {
            PWord_t pv = (PWord_t)JudyHSIns(&j, (void *)k.data(),
                                            (Word_t)k.size(), PJE0);
            if (pv == NULL || pv == (PWord_t)PPJERR) fatal("JudyHSIns failed");
            auto it = o.find(k);
            if (it == o.end()) {
                if (*pv != 0)
                    divergence("JHSI(\"%s\"): new slot not zero-initialized "
                               "(0x%lx)", esc(k).c_str(), *pv);
            } else if (*pv != it->second) {
                divergence("JHSI(\"%s\"): existing slot 0x%lx != oracle 0x%lx",
                           esc(k).c_str(), *pv, it->second);
            }
            Word_t val = (Word_t)rng.next();
            *pv = val;
            o[k] = val;
        } else if (r < 60) {
            int rc = JudyHSDel(&j, (void *)k.data(), (Word_t)k.size(), PJE0);
            if (rc == JERR) fatal("JudyHSDel JERR");
            int e = (int)o.erase(k);
            if (rc != e)
                divergence("JHSD(\"%s\" len %zu): rc=%d oracle expects %d",
                           esc(k).c_str(), k.size(), rc, e);
        } else {
            PWord_t pv = chk_slot(
                JudyHSGet(j, (void *)k.data(), (Word_t)k.size()), "JudyHSGet");
            auto it = o.find(k);
            if ((pv != NULL) != (it != o.end()))
                divergence("JHSG(\"%s\" len %zu): %s, oracle says %s",
                           esc(k).c_str(), k.size(), pv ? "hit" : "miss",
                           it != o.end() ? "hit" : "miss");
            if (pv && *pv != it->second)
                divergence("JHSG(\"%s\"): value 0x%lx != oracle 0x%lx",
                           esc(k).c_str(), *pv, it->second);
        }
        if (rng.chance(8192)) {
            g.phase = "freeall";
            Word_t bytes = JudyHSFreeArray(&j, PJE0);
            if (!o.empty() && bytes == 0)
                divergence("JHSFA freed 0 bytes with %zu keys stored", o.size());
            if (j != NULL) divergence("JHSFA left the array pointer non-NULL");
            o.clear();
        }
        if ((g.op + 1) % 4096 == 0) {
            g.phase = "verify-all";
            judyhs_verify_all(j, o);
        }
    }
    g.phase = "final";
    judyhs_verify_all(j, o);
    JudyHSFreeArray(&j, PJE0);
}

/* =============================================================== dispatch */

struct Domain {
    const char *name;
    const char *const *corpora;
    int ncorpora;
    void (*run)(int corpus_or_dist);
};

static const Domain domains[] = {
    {"judy1", wdist_name, 6, run_judy1},
    {"judyl", wdist_name, 6, run_judyl},
    {"judysl", scorpus_name, 6, run_judysl},
    {"judyhs", hcorpus_name, 6, run_judyhs},
};
static const int ndomains = 4;

static void run_cell(int d, int c, uint64_t seed, uint64_t nops, bool bulk) {
    g.domain = domains[d].name;
    g.corpus = domains[d].corpora[c];
    g.seed = seed;
    g.nops = nops;
    g.op = 0;
    g.phase = "start";
    g.bulk = bulk;
    printf("run: domain=%-6s corpus=%-9s seed=0x%016" PRIx64 " ops=%" PRIu64
           " bulk=%s\n",
           g.domain, g.corpus, seed, nops, bulk ? "on" : "off");
    fflush(stdout);
    domains[d].run(c);
}

static uint64_t fnv1a(const char *s) {
    uint64_t h = 0xCBF29CE484222325ULL;
    for (; *s; s++) h = (h ^ (uint8_t)*s) * 0x100000001B3ULL;
    return h;
}

static int usage(void) {
    fprintf(stderr,
            "usage: diffuzz smoke [ops]\n"
            "       diffuzz soak <seconds> [seed]\n"
            "       diffuzz one <domain> <corpus> <seed> [ops]\n"
            "       diffuzz list\n"
            "flags: --no-bulk   skip Judy1SetArray/JudyLInsArray bulk ops\n"
            "                   (stock 1.0.5 fails them by design: issue #127)\n");
    return 2;
}

int main(int argc, char **argv) {
    bool bulk = true;
    std::vector<const char *> args;
    for (int i = 1; i < argc; i++) {
        if (strcmp(argv[i], "--no-bulk") == 0)
            bulk = false;
        else
            args.push_back(argv[i]);
    }
    if (args.empty()) return usage();

    if (strcmp(args[0], "list") == 0) {
        for (int d = 0; d < ndomains; d++)
            for (int c = 0; c < domains[d].ncorpora; c++)
                printf("%s %s\n", domains[d].name, domains[d].corpora[c]);
        return 0;
    }

    if (strcmp(args[0], "smoke") == 0) {
        uint64_t nops = args.size() > 1 ? strtoull(args[1], NULL, 0) : 60000;
        if (nops == 0) return usage();
        int cells = 0;
        for (int d = 0; d < ndomains; d++) {
            for (int c = 0; c < domains[d].ncorpora; c++) {
                uint64_t base =
                    fnv1a(domains[d].name) ^ fnv1a(domains[d].corpora[c]);
                for (uint64_t v = 0; v < 2; v++) {
                    run_cell(d, c, base + v * 0x9E3779B97F4A7C15ULL, nops,
                             bulk);
                    cells++;
                }
            }
        }
        printf("smoke OK: %d cells, no divergence\n", cells);
        return 0;
    }

    if (strcmp(args[0], "soak") == 0) {
        if (args.size() < 2) return usage();
        uint64_t secs = strtoull(args[1], NULL, 0);
        uint64_t base;
        if (args.size() > 2) {
            base = strtoull(args[2], NULL, 0);
        } else {
            base = (uint64_t)time(NULL) * 0x9E3779B97F4A7C15ULL ^
                   (uint64_t)clock();
        }
        printf("soak: %" PRIu64 "s base seed 0x%" PRIx64
               " (per-cell seeds printed below)\n",
               secs, base);
        Rng pick(base);
        time_t start = time(NULL);
        uint64_t cells = 0;
        while ((uint64_t)(time(NULL) - start) < secs) {
            int d = (int)pick.below(ndomains);
            int c = (int)pick.below(domains[d].ncorpora);
            run_cell(d, c, pick.next(), 100000, bulk);
            cells++;
        }
        printf("soak OK: %" PRIu64 " cells, no divergence\n", cells);
        return 0;
    }

    if (strcmp(args[0], "one") == 0) {
        if (args.size() < 4) return usage();
        int d = -1, c = -1;
        for (int i = 0; i < ndomains; i++)
            if (strcmp(args[1], domains[i].name) == 0) d = i;
        if (d < 0) {
            fprintf(stderr, "unknown domain \"%s\"\n", args[1]);
            return usage();
        }
        for (int i = 0; i < domains[d].ncorpora; i++)
            if (strcmp(args[2], domains[d].corpora[i]) == 0) c = i;
        if (c < 0) {
            fprintf(stderr, "unknown corpus \"%s\" for domain %s\n", args[2],
                    args[1]);
            return usage();
        }
        uint64_t seed = strtoull(args[3], NULL, 0);
        uint64_t nops = args.size() > 4 ? strtoull(args[4], NULL, 0) : 60000;
        run_cell(d, c, seed, nops, bulk);
        printf("ok: no divergence\n");
        return 0;
    }

    return usage();
}
