/* Decompose: how much of a php-judy op is actual Judy work?
 * Measures raw JudyL point lookup in C, same access pattern the PHP
 * benchmark uses, so the two are directly comparable per operation. */
#include <Judy.h>
#include <stdio.h>
#include <stdlib.h>
#include <time.h>

static double now_ns(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (double)ts.tv_sec * 1e9 + (double)ts.tv_nsec;
}

int main(int argc, char **argv) {
    Word_t n = (argc > 1) ? (Word_t)strtoul(argv[1], NULL, 10) : 1000000;
    Word_t reps = (argc > 2) ? (Word_t)strtoul(argv[2], NULL, 10) : 5000000;
    Pvoid_t J = (Pvoid_t)NULL;
    PWord_t pv;

    for (Word_t i = 0; i < n; i++) { JLI(pv, J, i); *pv = i * 3; }

    /* xorshift keeps key generation cheap and identical in shape to the PHP side */
    unsigned long s = 88172645463325252UL, sink = 0;
    double t0 = now_ns();
    for (Word_t r = 0; r < reps; r++) {
        s ^= s << 13; s ^= s >> 7; s ^= s << 17;
        Word_t k = (Word_t)(s % n);
        JLG(pv, J, k);
        if (pv) sink += *pv;
    }
    double t1 = now_ns();

    long freed; (void)freed; JLFA(freed, J);
    printf("C   JudyLGet   n=%lu reps=%lu  %.2f ns/op  (sink=%lu)\n",
           (unsigned long)n, (unsigned long)reps, (t1 - t0) / (double)reps, sink);
    return 0;
}
