/* libart (Adaptive Radix Tree) vs libJudy (JudySL) on the workload php-judy
 * actually competes on: ordered string keys with prefix operations.
 *
 * Run as: ./cmp art|judy [n]
 * One structure per process so peak RSS is attributable.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <sys/resource.h>
#include <Judy.h>
#include "art.h"

static double now_ns(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (double)ts.tv_sec * 1e9 + (double)ts.tv_nsec;
}

/* ru_maxrss is kilobytes on Linux but BYTES on macOS/BSD. Normalise to KB so
 * the reported figures mean the same thing on both. */
static long peak_rss_kb(void) {
    struct rusage ru;
    getrusage(RUSAGE_SELF, &ru);
#if defined(__APPLE__) || defined(BSD)
    return ru.ru_maxrss / 1024;
#else
    return ru.ru_maxrss;
#endif
}

#define KEYLEN 40

/* 10 keys per user, so a prefix names a real group of 10. */
static void make_key(char *buf, unsigned long i) {
    snprintf(buf, KEYLEN, "user:%08lu:f%lu", i / 10, i % 10);
}

static int noop_cb(void *data, const unsigned char *k, uint32_t klen, void *val) {
    (void)k; (void)klen; (void)val;
    (*(unsigned long *)data)++;
    return 0;
}

int main(int argc, char **argv) {
    const char *which = (argc > 1) ? argv[1] : "judy";
    unsigned long n = (argc > 2) ? strtoul(argv[2], NULL, 10) : 1000000;
    int use_art = (strcmp(which, "art") == 0);

    long rss0 = peak_rss_kb();
    char key[KEYLEN];
    double t0, t1;

    art_tree at;
    Pvoid_t sl = (Pvoid_t)NULL;
    PWord_t pv;

    if (use_art) art_tree_init(&at);

    /* insert */
    t0 = now_ns();
    for (unsigned long i = 0; i < n; i++) {
        make_key(key, i);
        if (use_art) {
            art_insert(&at, (unsigned char *)key, (int)strlen(key) + 1, (void *)(i + 1));
        } else {
            JSLI(pv, sl, (uint8_t *)key);
            *pv = i + 1;
        }
    }
    t1 = now_ns();
    double ins_ns = (t1 - t0) / (double)n;
    long rss_after_insert = peak_rss_kb();

    /* random point lookup */
    unsigned long s = 88172645463325252UL, hits = 0, reps = 2000000;
    t0 = now_ns();
    for (unsigned long r = 0; r < reps; r++) {
        s ^= s << 13; s ^= s >> 7; s ^= s << 17;
        make_key(key, s % n);
        if (use_art) {
            if (art_search(&at, (unsigned char *)key, (int)strlen(key) + 1)) hits++;
        } else {
            JSLG(pv, sl, (uint8_t *)key);
            if (pv) hits++;
        }
    }
    t1 = now_ns();
    double get_ns = (t1 - t0) / (double)reps;

    /* full ordered iteration */
    unsigned long seen = 0;
    t0 = now_ns();
    if (use_art) {
        art_iter(&at, noop_cb, &seen);
    } else {
        uint8_t ik[KEYLEN]; ik[0] = '\0';
        JSLF(pv, sl, ik);
        while (pv) { seen++; JSLN(pv, sl, ik); }
    }
    t1 = now_ns();
    double iter_ns = (t1 - t0) / (double)(seen ? seen : 1);

    /* prefix scan: one 'user:00000042' group */
    const char *pfx = "user:00000042:";
    unsigned long pfound = 0;
    t0 = now_ns();
    if (use_art) {
        art_iter_prefix(&at, (unsigned char *)pfx, (int)strlen(pfx), noop_cb, &pfound);
    } else {
        uint8_t pk[KEYLEN];
        snprintf((char *)pk, KEYLEN, "%s", pfx);
        JSLF(pv, sl, pk);
        while (pv && strncmp((char *)pk, pfx, strlen(pfx)) == 0) { pfound++; JSLN(pv, sl, pk); }
    }
    t1 = now_ns();
    double pfx_us = (t1 - t0) / 1000.0;

    printf("%-5s n=%lu  insert %6.1f ns/op | get %6.1f ns/op | iter %5.1f ns/key | prefix %7.2f us (%lu keys) | peakRSS %6.1f MB (delta %6.1f MB) | hits=%lu seen=%lu\n",
           use_art ? "ART" : "JUDY", n, ins_ns, get_ns, iter_ns, pfx_us, pfound,
           rss_after_insert / 1024.0, (rss_after_insert - rss0) / 1024.0, hits, seen);
    return 0;
}
