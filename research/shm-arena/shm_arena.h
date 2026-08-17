/*
 * shm_arena.h - shared-memory arena allocator for libJudy (Step-0 spike, issue #83)
 *
 * NOT production code. NOT part of the shipping php-judy extension.
 *
 * Judy's allocator is a link-time hook: libJudy calls the global symbols
 * JudyMalloc/JudyFree/JudyMallocVirtual/JudyFreeVirtual. Defining them in the
 * final link unit replaces the stock malloc-backed implementation.
 *
 * Alignment contract (verified against Judy-1.0.5 sources, sha256
 * d2704089f85fdb6f2cd7e77be21170ced4b4375c03ef1ad4cf1075bd414a63eb):
 *   src/JudyCommon/JudyMallocIF.c:140-147 defines
 *       MALLOCBITS_VALUE 0x3, MALLOCBITS_MASK 0x7
 *   and asserts ((addr & 0x7) == 0) before OR-ing the tag bits in.
 *   JudyPrivate.h:365-368 documents the low bits as reserved so that "Judy
 *   mallocd objects [can] come from different malloc() namespaces".
 *   => every returned address MUST have its low 3 bits clear (8-byte aligned).
 */

#ifndef SHM_ARENA_H
#define SHM_ARENA_H

#include <stddef.h>
#include <stdint.h>
#include <sys/types.h>

/* Judy allocates in words. Largest single Judy node on 64-bit is jbu_t
 * (256 * sizeof(jp_t) = 4096 bytes = 512 words). 1024 covers it with headroom;
 * anything larger falls into the oversize path and is counted separately. */
#define ARENA_MAX_WORDS 1024u

/* Minimum alignment demanded by Judy (low 3 bits clear). */
#define ARENA_ALIGN 8u

typedef enum {
    ARENA_MODE_FREELIST = 0, /* size-class freelist + bump for fresh memory */
    ARENA_MODE_BUMP     = 1  /* bump only; JudyFree is a no-op (never reuses) */
} arena_mode_t;

/*
 * Arena header. Lives at offset 0 of the shared mapping.
 *
 * All internal links are BYTE OFFSETS from the arena base, never pointers, so
 * the arena's own metadata is position-independent. Only the Judy tree itself
 * stores absolute pointers -- that is the fork-inheritance dependency this
 * spike exists to test (gate 2).
 */
typedef struct {
    uint64_t magic;
    uint64_t size;      /* total bytes of the mapping */
    uint64_t bump;      /* offset of next never-yet-allocated byte */
    uint64_t hdr_bytes; /* offset of first allocatable byte */
    uint32_t mode;      /* arena_mode_t */
    uint32_t _pad;

    /* free_head[w] = byte offset of first free block of exactly w words, or 0 */
    uint64_t free_head[ARENA_MAX_WORDS + 1];

    /* ---- instrumentation (gate 4) ---- */
    uint64_t alloc_calls[ARENA_MAX_WORDS + 1];
    uint64_t free_calls[ARENA_MAX_WORDS + 1];
    int64_t  live_blocks[ARENA_MAX_WORDS + 1]; /* currently-live count per class */
    int64_t  peak_blocks[ARENA_MAX_WORDS + 1]; /* high-water live count per class */

    uint64_t n_alloc;        /* total JudyMalloc calls */
    uint64_t n_free;         /* total JudyFree calls */
    uint64_t n_reused;       /* allocations satisfied from a freelist */
    uint64_t n_bumped;       /* allocations satisfied from fresh bump space */
    uint64_t n_oversize;     /* allocations above ARENA_MAX_WORDS */
    uint64_t n_oom;          /* allocations that failed (arena exhausted) */

    uint64_t live_bytes;     /* payload bytes currently allocated */
    uint64_t peak_live_bytes;/* high-water of live_bytes */
    uint64_t free_bytes;     /* payload bytes sitting on freelists */
} arena_hdr_t;

/* Create a fresh MAP_SHARED anonymous arena of `bytes`. Returns base or NULL. */
arena_hdr_t *arena_create(size_t bytes, arena_mode_t mode);

/* Create at a caller-chosen address with MAP_FIXED semantics (gate 2 variant).
 * `at` must be page-aligned. Returns base (== at on success) or NULL. */
arena_hdr_t *arena_create_fixed(void *at, size_t bytes, arena_mode_t mode);

void arena_destroy(arena_hdr_t *a);

/* Install `a` as the arena backing JudyMalloc/JudyFree for this process.
 * Must be called before any Judy operation. Passing NULL routes Judy back to
 * the process heap (used for the malloc baseline in gate 4). */
void arena_install(arena_hdr_t *a);

arena_hdr_t *arena_current(void);

/* Audit hook (gate 1). Called after every successful allocation and before
 * every release. `is_free` is 0 for alloc, 1 for free. Used to verify Judy's
 * alloc/free size symmetry and to detect overlapping or double-freed blocks. */
typedef void (*arena_audit_fn)(void *addr, size_t words, int is_free);
void arena_set_audit(arena_audit_fn fn);

/* Reset statistics without discarding allocations. */
void arena_stats_reset(arena_hdr_t *a);

/* Bytes of the mapping actually touched (bump high-water). */
static inline uint64_t arena_footprint(const arena_hdr_t *a) { return a->bump; }

#endif /* SHM_ARENA_H */
