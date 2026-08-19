/* o5pbench.c -- O5 REOPEN (partition) gate bench: library JudyLMultiGet
 * (now internally counting-partitioned) vs serial JudyLGet on identical
 * PREGENERATED probe streams.
 *
 * Differences from the original o5bench.c, per the adversarial review:
 *  - CORRECTED serial baseline: the probe stream is materialized into
 *    probe[] BEFORE timing; the serial kernel reads probe[r] instead of
 *    computing the next key through a dependent xorshift chain inside
 *    the timed loop (the chain inflated the old serial ns/op ~25-30%,
 *    flattering batched ratios).  The old-style kernel is retained and
 *    reported as "serialold" so both baseline styles appear in the
 *    record.
 *  - Heterogeneous-batch corpora: wmixPP (PP% dense contiguous + sparse
 *    64-bit), wmixsPP (dense STRIDED x16), wmixcPP (dense CLUSTERED in
 *    1024-key runs at random 40-bit bases), wmix32_PP (PP% dense + sparse
 *    32-bit -- keys whose top 4 bytes are all zero; catches a partition
 *    that only looks at high bytes).
 *
 * Output (machine-parseable):
 *   FEAT,<corpus>,multiget,<present|absent>
 *   MEM,<corpus>,<n>,<jlmu-bytes>
 *   GATE,<corpus>,<n>,<reps>,<serial-hits>,<mg-hits>,<PASS|FAIL>
 *   RES,<corpus>,<n>,<reps>,<kernel>,<pass>,<ns-per-op>,<hits>
 *
 * usage: o5pbench <corpus> <n> <reps> [passes=1] [blocks=256,1024]
 */
#define _GNU_SOURCE
#include <Judy.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <time.h>

extern Word_t JudyLMultiGet(Pcvoid_t PArray, const Word_t *PIndex,
                            PPvoid_t *PPValue, Word_t Count)
    __attribute__((weak));

static double now_ns(void){struct timespec t;clock_gettime(CLOCK_MONOTONIC,&t);
    return (double)t.tv_sec*1e9+(double)t.tv_nsec;}

static uint64_t sm_s;
static uint64_t sm(void){uint64_t z=(sm_s+=0x9E3779B97F4A7C15ULL);
    z=(z^(z>>30))*0xBF58476D1CE4E5B9ULL;z=(z^(z>>27))*0x94D049BB133111EBULL;return z^(z>>31);}

/* ------------------------------------------------------------- corpora  */
/* unimodal generators verbatim from o5bench.c / mlp2.c (comparability);
 * wmix* families added for the reopen. */
static int gen_corpus(const char *corpus, Word_t *wk, unsigned long n)
{
    if (!strncmp(corpus, "wmix", 4)) {
        const char *p = corpus + 4;
        int strided = 0, clustered = 0, sparse32 = 0;
        if      (*p == 's') { strided = 1; p++; }
        else if (*p == 'c') { clustered = 1; p++; }
        else if (!strncmp(p, "32_", 3)) { sparse32 = 1; p += 3; }
        int pct = atoi(p);
        if (pct < 0 || pct > 100) return -1;
        unsigned long dcount = (unsigned long)((double)n * pct / 100.0);
        Word_t curbase = 0;
        for (unsigned long i = 0; i < n; i++) {
            if (i < dcount) {
                if (strided)        wk[i] = i * 16;
                else if (clustered) {
                    if ((i & 1023) == 0) curbase = sm() & ((1UL<<40)-1);
                    wk[i] = curbase + (i & 1023);
                }
                else                wk[i] = i;
            } else {
                wk[i] = sparse32 ? (sm() & 0xffffffffUL) : sm();
            }
        }
        return 0;
    }
    for (unsigned long i = 0; i < n; i++) {
        if      (!strcmp(corpus,"wdense"))   wk[i] = i;
        else if (!strcmp(corpus,"wsparse"))  wk[i] = sm();
        else if (!strcmp(corpus,"wclust"))   wk[i] = (sm()%4096)*65536UL + (i&0xffff);
        else if (!strncmp(corpus,"wbase",5)) {
            unsigned base = (unsigned)atoi(corpus+5);
            unsigned long v = i, k = 0;
            for (int b = 0; b < 8; b++) { k |= (v % base) << (8*b); v /= base; }
            wk[i] = k;
        }
        else if (!strncmp(corpus,"wrand",5)) {
            int bits = atoi(corpus+5);
            wk[i] = sm() & ((bits >= 64) ? ~0UL : ((1UL << bits) - 1));
        }
        else if (!strcmp(corpus,"wleaf1"))   wk[i] = (i/12)*256 + (i%12)*21;
        else if (!strcmp(corpus,"wimm3"))    wk[i] = (i/3)*256 + (i%3)*80;
        else if (!strcmp(corpus,"wimm6"))    wk[i] = (i/6)*256 + (i%6)*40;
        else if (!strcmp(corpus,"wimm1"))    wk[i] = i*256;
        else if (!strcmp(corpus,"wpair"))    wk[i] = (i/2)*65536UL + (i%2)*300;
        else if (!strcmp(corpus,"wtrip"))    wk[i] = (i/2)*16777216UL + (i%2)*70000;
        else if (!strcmp(corpus,"wbl")) {
            unsigned long v = i, k = 0;
            for (int b = 0; b < 8; b++) { k |= ((v % 3) * 85UL) << (8*b); v /= 3; }
            wk[i] = k;
        }
        else if (!strcmp(corpus,"wroot"))    wk[i] = i * 977;
        else return -1;
    }
    return 0;
}

/* ------------------------------------------------------------- kernels  */

/* OLD-STYLE serial baseline (dependent xorshift chain inside the timed
 * loop) -- kept only so the record carries both styles: */
static unsigned long kern_serial_old(Pvoid_t J, const Word_t *keys,
                                     unsigned long n, unsigned long reps)
{
    PWord_t pv; unsigned long hits = 0; uint64_t rs = 88172645463325252ULL;
    for (unsigned long r = 0; r < reps; r++) {
        rs ^= rs<<13; rs ^= rs>>7; rs ^= rs<<17;
        JLG(pv, J, keys[rs % n]); if (pv) hits++;
    }
    return hits;
}

/* CORRECTED serial baseline: probe stream pregenerated. */
static unsigned long kern_serial(Pvoid_t J, const Word_t *probe,
                                 unsigned long reps)
{
    PWord_t pv; unsigned long hits = 0;
    for (unsigned long r = 0; r < reps; r++) {
        JLG(pv, J, probe[r]); if (pv) hits++;
    }
    return hits;
}

#define MAXBLK 4096

static unsigned long kern_mg(Pvoid_t J, const Word_t *probe,
                             unsigned long reps, unsigned long blk)
{
    Word_t   kbuf[MAXBLK];
    PPvoid_t vbuf[MAXBLK];
    unsigned long hits = 0, done = 0;

    if (blk > MAXBLK) blk = MAXBLK;
    while (done < reps) {
        unsigned long m = reps - done; if (m > blk) m = blk;
        memcpy(kbuf, probe + done, m * sizeof(Word_t));
        hits += JudyLMultiGet(J, kbuf, vbuf, m);
        done += m;
    }
    return hits;
}

/* ------------------------------------------------------------- main     */

int main(int argc, char **argv)
{
    const char *corpus = (argc>1)?argv[1]:"wdense";
    unsigned long n    = (argc>2)?strtoul(argv[2],NULL,10):1000000;
    unsigned long reps = (argc>3)?strtoul(argv[3],NULL,10):2000000;
    int passes         = (argc>4)?atoi(argv[4]):1;
    const char *blkcsv = (argc>5)?argv[5]:"256,1024";
    sm_s = 12345;

    int have_mg = (JudyLMultiGet != NULL);
    printf("FEAT,%s,multiget,%s\n", corpus, have_mg ? "present" : "absent");

    Word_t *keys = malloc(n*sizeof(Word_t));
    if (!keys || gen_corpus(corpus, keys, n) != 0) {
        fprintf(stderr,"bad corpus %s\n",corpus); return 2; }

    Pvoid_t J=(Pvoid_t)NULL; PWord_t pv;
    for(unsigned long i=0;i<n;i++){ JLI(pv,J,keys[i]); *pv=i+1; }

    Word_t mem; JLMU(mem, J);
    printf("MEM,%s,%lu,%lu\n", corpus, n, mem);

    /* Pregenerated probe stream, identical to the old chain's sequence. */
    Word_t *probe = malloc(reps*sizeof(Word_t));
    if (!probe) { fprintf(stderr,"oom probe\n"); return 2; }
    { uint64_t rs = 88172645463325252ULL;
      for (unsigned long r = 0; r < reps; r++) {
          rs ^= rs<<13; rs ^= rs>>7; rs ^= rs<<17;
          probe[r] = keys[rs % n];
      } }

    /* correctness gate: identical probe streams must yield identical hit
       counts (per-slot pointer equality is the fuzzer's job). */
    if (have_mg) {
        unsigned long gr = (reps < 200000) ? reps : 200000;
        unsigned long hs = kern_serial(J, probe, gr);
        unsigned long hm = kern_mg(J, probe, gr, 256);
        unsigned long hm2 = kern_mg(J, probe, gr, 1024);
        int ok = (hs == hm && hs == hm2);
        printf("GATE,%s,%lu,%lu,%lu,%lu,%s\n", corpus, n, gr, hs, hm,
               ok ? "PASS" : "FAIL");
        if (!ok) return 1;
    }

    unsigned long h;
    h = kern_serial(J, probe, reps/4 + 1);                /* warmup */
    for (int ps = 1; ps <= passes; ps++) {
        double a = now_ns();
        h = kern_serial(J, probe, reps);
        double b = now_ns();
        printf("RES,%s,%lu,%lu,serial,%d,%.4f,%lu\n",
               corpus, n, reps, ps, (b-a)/reps, h);
    }
    h = kern_serial_old(J, keys, n, reps/4 + 1);          /* warmup */
    for (int ps = 1; ps <= passes; ps++) {
        double a = now_ns();
        h = kern_serial_old(J, keys, n, reps);
        double b = now_ns();
        printf("RES,%s,%lu,%lu,serialold,%d,%.4f,%lu\n",
               corpus, n, reps, ps, (b-a)/reps, h);
    }
    if (have_mg) {
        char bc[128]; strncpy(bc, blkcsv, sizeof bc - 1); bc[sizeof bc-1]=0;
        for (char *tok = strtok(bc, ","); tok; tok = strtok(NULL, ",")) {
            unsigned long blk = strtoul(tok, NULL, 10);
            if (blk < 1 || blk > MAXBLK) continue;
            h = kern_mg(J, probe, reps/4 + 1, blk);       /* warmup */
            for (int ps = 1; ps <= passes; ps++) {
                double a = now_ns();
                h = kern_mg(J, probe, reps, blk);
                double b = now_ns();
                printf("RES,%s,%lu,%lu,mg%lu,%d,%.4f,%lu\n",
                       corpus, n, reps, blk, ps, (b-a)/reps, h);
            }
        }
    }
    return 0;
}
