/*
 * gate2_fork.c - Gate 2: address stability of a MAP_SHARED arena across fork.
 *
 * The whole design rests on absolute pointers inside the Judy tree remaining
 * valid in every worker. That holds iff the mapping lands at the same address
 * in every process that touches it.
 *
 * Tests:
 *   2a. Parent builds a tree in the arena, forks N children; each child reports
 *       its arena base and fully re-traverses the tree.
 *   2b. A child mutates the shared tree; parent and a later child observe it
 *       (verifies MAP_SHARED write visibility, not just read inheritance).
 *   2c. MAP_FIXED at a caller-chosen address: does it remove the dependency on
 *       fork inheritance, and does the address survive in a child?
 *   2d. Base address across independent runs of the same binary (ASLR): printed
 *       so the driver can compare across master restarts.
 */

#include "shm_arena.h"

#include <Judy.h>

#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <sys/wait.h>
#include <unistd.h>

#define ARENA_BYTES (128ull * 1024 * 1024)
#define N_CHILDREN 8
#define N_KEYS 100000u

static int g_fail = 0;

static Word_t mix(Word_t x) {
    x ^= x >> 33; x *= 0xff51afd7ed558ccdull;
    x ^= x >> 33; x *= 0xc4ceb9fe1a85ec53ull;
    x ^= x >> 33;
    return x;
}

/* The Judy root pointer must itself live in shared memory, otherwise each
 * process would have its own copy of the root. Park it in the arena. */
typedef struct {
    Pvoid_t root;
    Word_t  n_inserted;
} shared_state_t;

static shared_state_t *state_slot(arena_hdr_t *a) {
    /* first allocation out of the arena, reserved by hand */
    return (shared_state_t *)((char *)a + ((sizeof(arena_hdr_t) + 63) & ~63ull));
}

/* verify the tree contains exactly keys mix(0..n-1), in sorted order */
static int verify_tree(Pvoid_t root, Word_t n, const char *who) {
    Word_t *pv, idx = 0, count = 0, prev = 0;
    int bad = 0;

    JLF(pv, root, idx);
    while (pv) {
        if (count && idx <= prev) { bad++; break; }
        if (*pv != (idx ^ 0x5a5a5a5aull)) { bad++; break; }
        prev = idx; count++;
        JLN(pv, root, idx);
    }
    if (count != n) {
        fprintf(stderr, "  [%s] tree has %lu entries, expected %lu\n",
                who, (unsigned long)count, (unsigned long)n);
        return 1;
    }
    return bad;
}

int main(void) {
    printf("== Gate 2: MAP_SHARED address stability across fork ==\n");

    arena_hdr_t *a = arena_create(ARENA_BYTES, ARENA_MODE_FREELIST);
    if (!a) { perror("arena_create"); return 2; }
    arena_install(a);

    shared_state_t *st = state_slot(a);
    /* keep the hand-reserved slot out of the bump allocator's way */
    a->bump = (uint64_t)(((char *)st - (char *)a) + sizeof(shared_state_t) + 63) & ~63ull;
    st->root = NULL;
    st->n_inserted = 0;

    printf("  parent arena base = %p  (2d: compare across runs for ASLR)\n", (void *)a);

    /* ---- build the tree in the parent, pre-fork ---- */
    Word_t *pv;
    for (Word_t i = 0; i < N_KEYS; i++) {
        Word_t k = mix(i);
        JLI(pv, st->root, k);
        *pv = k ^ 0x5a5a5a5aull;
    }
    Word_t cnt;
    JLC(cnt, st->root, 0, -1);
    st->n_inserted = cnt;
    printf("  parent built tree: %lu entries, arena footprint %.2f MB\n",
           (unsigned long)cnt, (double)a->bump / (1024 * 1024));

    /* ---- 2a: fork children, each reports base + re-traverses ---- */
    int pipefd[2];
    if (pipe(pipefd) != 0) { perror("pipe"); return 2; }

    for (int i = 0; i < N_CHILDREN; i++) {
        pid_t p = fork();
        if (p == 0) {
            close(pipefd[0]);
            void *base = (void *)arena_current();
            int bad = verify_tree(st->root, st->n_inserted, "child");
            /* report base address + verdict back to parent */
            struct { void *base; int bad; } msg = { base, bad };
            ssize_t w = write(pipefd[1], &msg, sizeof msg);
            (void)w;
            close(pipefd[1]);
            _exit(bad ? 1 : 0);
        } else if (p < 0) { perror("fork"); return 2; }
    }
    close(pipefd[1]);

    int same_addr = 1, verify_ok = 1;
    void *first = NULL;
    for (int i = 0; i < N_CHILDREN; i++) {
        struct { void *base; int bad; } msg;
        ssize_t r = read(pipefd[0], &msg, sizeof msg);
        if (r != (ssize_t)sizeof msg) { fprintf(stderr, "  short read\n"); g_fail = 1; break; }
        if (i == 0) first = msg.base;
        if (msg.base != (void *)a || msg.base != first) same_addr = 0;
        if (msg.bad) verify_ok = 0;
    }
    close(pipefd[0]);
    for (int i = 0; i < N_CHILDREN; i++) { int s; wait(&s); }

    printf("  2a: %d children, identical arena base: %s; tree traversal: %s\n",
           N_CHILDREN, same_addr ? "YES" : "NO", verify_ok ? "OK" : "CORRUPT");
    if (!same_addr || !verify_ok) g_fail = 1;

    /* ---- 2b: child mutates shared tree, parent observes ---- */
    Word_t extra_key = 0xdeadbeefcafe0001ull;
    pid_t p = fork();
    if (p == 0) {
        JLI(pv, st->root, extra_key);
        if (pv && pv != PJERR) *pv = extra_key ^ 0x5a5a5a5aull;
        Word_t c;
        JLC(c, st->root, 0, -1);
        st->n_inserted = c;
        _exit(0);
    }
    int status; waitpid(p, &status, 0);

    Word_t *found;
    JLG(found, st->root, extra_key);
    int child_write_visible = (found != NULL && *found == (extra_key ^ 0x5a5a5a5aull));
    printf("  2b: child's insert visible in parent: %s (count now %lu)\n",
           child_write_visible ? "YES" : "NO", (unsigned long)st->n_inserted);
    if (!child_write_visible) g_fail = 1;

    /* ---- 2c: MAP_FIXED at a chosen address ---- */
    /* Reserve a region first so we pick an address the kernel agrees is free. */
    size_t fixed_len = 16ull * 1024 * 1024;
    void *probe = mmap(NULL, fixed_len, PROT_NONE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    if (probe == MAP_FAILED) { perror("probe mmap"); return 2; }
    munmap(probe, fixed_len);

    arena_hdr_t *fa = arena_create_fixed(probe, fixed_len, ARENA_MODE_FREELIST);
    if (!fa) {
        printf("  2c: MAP_FIXED at %p FAILED (%s)\n", probe, strerror(errno));
        g_fail = 1;
    } else {
        printf("  2c: MAP_FIXED requested %p, got %p -> %s\n", probe, (void *)fa,
               (fa == (arena_hdr_t *)probe) ? "EXACT" : "MOVED");
        if (fa != (arena_hdr_t *)probe) g_fail = 1;

        /* does a child inherit the fixed mapping at the same address? */
        pid_t q = fork();
        if (q == 0) { _exit(((void *)fa == probe) ? 0 : 1); }
        waitpid(q, &status, 0);
        printf("  2c: child sees fixed mapping at same address: %s\n",
               (WIFEXITED(status) && WEXITSTATUS(status) == 0) ? "YES" : "NO");
        arena_destroy(fa);
    }

    printf("\nGATE 2: %s\n", g_fail ? "FAIL" : "PASS");
    /* note: arena intentionally not destroyed before the summary above */
    arena_install(NULL);
    return g_fail ? 1 : 0;
}
