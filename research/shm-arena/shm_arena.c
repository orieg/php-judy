/*
 * shm_arena.c - shared-memory arena allocator for libJudy (Step-0 spike, issue #83)
 *
 * NOT production code. See shm_arena.h for the alignment contract.
 */

#include "shm_arena.h"

#include <Judy.h>

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <unistd.h>

#define ARENA_MAGIC 0x4A554459414E4131ull /* "JUDYANA1" */

/* The installed arena. Deliberately process-local: each forked worker inherits
 * a copy of this pointer, and (gate 2) it must point at the same address in
 * every worker for the shared tree to be traversable. */
static arena_hdr_t *g_arena = NULL;

/* Free-block link: stored in the first word of a free block, as a byte offset
 * from the arena base. 0 terminates the list (offset 0 is the header, so it can
 * never be a real block). */
typedef struct {
    uint64_t next;
} free_link_t;

static inline size_t align_up(size_t v, size_t a) { return (v + (a - 1)) & ~(a - 1); }

static inline void *off2ptr(arena_hdr_t *a, uint64_t off) {
    return off ? (void *)((char *)a + off) : NULL;
}
static inline uint64_t ptr2off(arena_hdr_t *a, void *p) {
    return p ? (uint64_t)((char *)p - (char *)a) : 0;
}

static arena_hdr_t *arena_init_mapping(void *base, size_t bytes, arena_mode_t mode) {
    arena_hdr_t *a = (arena_hdr_t *)base;
    memset(a, 0, sizeof(*a));
    a->magic     = ARENA_MAGIC;
    a->size      = bytes;
    a->hdr_bytes = align_up(sizeof(arena_hdr_t), ARENA_ALIGN);
    a->bump      = a->hdr_bytes;
    a->mode      = (uint32_t)mode;
    return a;
}

arena_hdr_t *arena_create(size_t bytes, arena_mode_t mode) {
    long pg = sysconf(_SC_PAGESIZE);
    if (pg <= 0) pg = 4096;
    bytes = align_up(bytes, (size_t)pg);

    void *base = mmap(NULL, bytes, PROT_READ | PROT_WRITE,
                      MAP_SHARED | MAP_ANONYMOUS, -1, 0);
    if (base == MAP_FAILED) return NULL;
    return arena_init_mapping(base, bytes, mode);
}

arena_hdr_t *arena_create_fixed(void *at, size_t bytes, arena_mode_t mode) {
    long pg = sysconf(_SC_PAGESIZE);
    if (pg <= 0) pg = 4096;
    bytes = align_up(bytes, (size_t)pg);

    void *base = mmap(at, bytes, PROT_READ | PROT_WRITE,
                      MAP_SHARED | MAP_ANONYMOUS | MAP_FIXED, -1, 0);
    if (base == MAP_FAILED) return NULL;
    return arena_init_mapping(base, bytes, mode);
}

void arena_destroy(arena_hdr_t *a) {
    if (!a) return;
    size_t n = (size_t)a->size;
    if (g_arena == a) g_arena = NULL;
    munmap((void *)a, n);
}

void arena_install(arena_hdr_t *a) { g_arena = a; }
arena_hdr_t *arena_current(void) { return g_arena; }

static arena_audit_fn g_audit = NULL;
void arena_set_audit(arena_audit_fn fn) { g_audit = fn; }

void arena_stats_reset(arena_hdr_t *a) {
    if (!a) return;
    memset(a->alloc_calls, 0, sizeof(a->alloc_calls));
    memset(a->free_calls, 0, sizeof(a->free_calls));
    memset(a->peak_blocks, 0, sizeof(a->peak_blocks));
    a->n_alloc = a->n_free = a->n_reused = a->n_bumped = 0;
    a->n_oversize = a->n_oom = 0;
    a->peak_live_bytes = a->live_bytes;
}

/* ------------------------------------------------------------------ */
/* Core allocate / release                                             */
/* ------------------------------------------------------------------ */

static void *arena_alloc(arena_hdr_t *a, size_t words) {
    size_t payload = align_up(words * sizeof(Word_t), ARENA_ALIGN);

    a->n_alloc++;
    if (words <= ARENA_MAX_WORDS) {
        a->alloc_calls[words]++;
        a->live_blocks[words]++;
        if (a->live_blocks[words] > a->peak_blocks[words])
            a->peak_blocks[words] = a->live_blocks[words];
    } else {
        a->n_oversize++;
    }

    /* 1. exact-size freelist (freelist mode only) */
    if (a->mode == ARENA_MODE_FREELIST && words <= ARENA_MAX_WORDS &&
        a->free_head[words] != 0) {
        uint64_t off = a->free_head[words];
        free_link_t *fl = (free_link_t *)off2ptr(a, off);
        a->free_head[words] = fl->next;
        a->free_bytes -= payload;
        a->n_reused++;
        a->live_bytes += payload;
        if (a->live_bytes > a->peak_live_bytes) a->peak_live_bytes = a->live_bytes;
        return (void *)fl;
    }

    /* 2. fresh bump space */
    if (a->bump + payload > a->size) {
        a->n_oom++;
        if (words <= ARENA_MAX_WORDS) a->live_blocks[words]--;
        return NULL;
    }
    void *p = (char *)a + a->bump;
    a->bump += payload;
    a->n_bumped++;
    a->live_bytes += payload;
    if (a->live_bytes > a->peak_live_bytes) a->peak_live_bytes = a->live_bytes;
    return p;
}

static void arena_release(arena_hdr_t *a, void *p, size_t words) {
    if (!p) return;
    size_t payload = align_up(words * sizeof(Word_t), ARENA_ALIGN);

    a->n_free++;
    if (words <= ARENA_MAX_WORDS) {
        a->free_calls[words]++;
        a->live_blocks[words]--;
    }
    a->live_bytes -= payload;

    /* Bump-only mode models "never reuse": the block is simply abandoned. */
    if (a->mode == ARENA_MODE_BUMP || words > ARENA_MAX_WORDS) return;

    free_link_t *fl = (free_link_t *)p;
    fl->next = a->free_head[words];
    a->free_head[words] = ptr2off(a, p);
    a->free_bytes += payload;
}

/* ------------------------------------------------------------------ */
/* Judy link-time hooks                                                */
/* ------------------------------------------------------------------ */

Word_t JudyMalloc(Word_t Words) {
    arena_hdr_t *a = g_arena;
    void *p;

    if (a == NULL) {
        /* baseline path: stock heap, matching Judy's own JudyMalloc.c */
        p = malloc((size_t)Words * sizeof(Word_t));
    } else {
        p = arena_alloc(a, (size_t)Words);
    }

    /* Judy requires the low 3 bits clear (MALLOCBITS_MASK 0x7). Enforce loudly:
     * a violation here is silent tree corruption in a DEBUG libJudy build. */
    if (p && ((uintptr_t)p & 0x7u) != 0) {
        fprintf(stderr, "FATAL: arena returned misaligned address %p\n", p);
        abort();
    }
    if (p && g_audit) g_audit(p, (size_t)Words, 0);
    return (Word_t)p;
}

void JudyFree(void *p, Word_t Words) {
    arena_hdr_t *a = g_arena;
    if (p && g_audit) g_audit(p, (size_t)Words, 1);
    if (a == NULL) {
        free(p);
        return;
    }
    arena_release(a, p, (size_t)Words);
}

Word_t JudyMallocVirtual(Word_t Words) { return JudyMalloc(Words); }
void JudyFreeVirtual(void *p, Word_t Words) { JudyFree(p, Words); }
