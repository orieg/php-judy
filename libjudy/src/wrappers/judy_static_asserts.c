/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compile-time environment pins for the bundled libJudy.  This TU is
 * variant-free (no JUDY1/JUDYL): it checks only what must hold before any
 * variant compiles.  Table-dimension pins live in Judy1Tables.c /
 * JudyLTables.c next to the data they guard; the immediate-capacity pins
 * live at the bottom of this file (added with the Stage 2 P1 patch: on
 * pristine sources they FAIL -- that is the #131 bug the P1 jp_1Index
 * widening fixes). */

#include <stddef.h>             /* offsetof */

#include "Judy.h"

/* This TU compiles no variant code, but JudyL.h needs the Pjv_t typedef
 * that JudyPrivate.h only emits under JUDYL, so define it for the header
 * pass.  The cJ1_/cJL_ constants asserted below depend only on
 * sizeof(jp_t) and cJU_BYTESPERWORD; everything else the define enables
 * is declarations this TU does not use. */
#define JUDYL 1
#include "Judy1.h"
#include "JudyL.h"

#define JUDY_PHP_STATIC_ASSERT(cond, name) \
        typedef char judy_php_static_assert_ ## name [(cond) ? 1 : -1]

/* Word_t must be exactly the machine word: every Judy structure, mask and
 * shift is derived from it.  On LLP64 (MSVC x64) this is what the P5 patch
 * to Judy.h guarantees. */
JUDY_PHP_STATIC_ASSERT(sizeof(Word_t) == sizeof(void *),
        word_t_is_pointer_sized);

#ifdef JU_64BIT
/* The build defines JU_64BIT (config.m4 / config.w32): internal structures
 * assume 8-byte words, and the pre-generated tables were produced under it. */
JUDY_PHP_STATIC_ASSERT(sizeof(Word_t) == 8, word_t_is_8_bytes_under_ju_64bit);
#else
/* A 32-bit build must NOT reuse the committed 64-bit tables; this pin is the
 * matching guard on the environment side. */
JUDY_PHP_STATIC_ASSERT(sizeof(Word_t) == 4, word_t_is_4_bytes_without_ju_64bit);
#endif

/* ------------------------------------------------------------------
 * Immediate-index capacity pins (Stage 2 P1, issues #131/#127/#142).
 *
 * A Judy1 immediate JP stores up to cJ1_IMMED<n>_MAXPOP1 n-byte indexes
 * CONTIGUOUSLY through jp_1Index (JudyCascade.c, JudyGet.c IMMED cases,
 * JudyIns.c/JudyDel.c leaf macros).  Upstream declared j_pi_1Index with
 * only sizeof(Word_t) bytes while cJ1_IMMED1_MAXPOP1 is 15 on 64-bit:
 * every access past index 7 was undefined behavior, exploited by gcc 15
 * at -O3 and by gcc 13/14 at -O2 -funroll-loops (#131 silent key loss).
 * P1 widens j_pi_1Index to (2 * sizeof(Word_t)) - 1 bytes inside a union
 * that keeps j_pi_LIndex at its old offset.  These pins fail on the
 * pristine layout and must pass forever after. */

#define JUDY_PHP_JP_1INDEX_BYTES  (sizeof(((jp_t *)0)->jp_1Index))
#define JUDY_PHP_JP_LINDEX_BYTES  (sizeof(((jp_t *)0)->jp_LIndex))

/* ABI pins: the widening must not change the JP object layout. */
JUDY_PHP_STATIC_ASSERT(sizeof(jp_t) == 2 * sizeof(Word_t),
        jp_t_is_two_words);
JUDY_PHP_STATIC_ASSERT(offsetof(jp_t, j_pi.j_pi_u.j_pi_s.j_pi_LIndex)
        == sizeof(Word_t),
        jp_lindex_offset_unchanged_at_word_1);
JUDY_PHP_STATIC_ASSERT(offsetof(jp_t, j_pi.j_pi_Type)
        == 2 * sizeof(Word_t) - 1,
        jp_type_offset_unchanged_at_last_byte);

/* Judy1 immediates: every type's worst case must fit jp_1Index. */
JUDY_PHP_STATIC_ASSERT(cJ1_IMMED1_MAXPOP1 * 1 <= JUDY_PHP_JP_1INDEX_BYTES,
        j1_immed1_fits_jp_1index);
JUDY_PHP_STATIC_ASSERT(cJ1_IMMED2_MAXPOP1 * 2 <= JUDY_PHP_JP_1INDEX_BYTES,
        j1_immed2_fits_jp_1index);
JUDY_PHP_STATIC_ASSERT(cJ1_IMMED3_MAXPOP1 * 3 <= JUDY_PHP_JP_1INDEX_BYTES,
        j1_immed3_fits_jp_1index);
#ifdef JU_64BIT
JUDY_PHP_STATIC_ASSERT(cJ1_IMMED4_MAXPOP1 * 4 <= JUDY_PHP_JP_1INDEX_BYTES,
        j1_immed4_fits_jp_1index);
JUDY_PHP_STATIC_ASSERT(cJ1_IMMED5_MAXPOP1 * 5 <= JUDY_PHP_JP_1INDEX_BYTES,
        j1_immed5_fits_jp_1index);
JUDY_PHP_STATIC_ASSERT(cJ1_IMMED6_MAXPOP1 * 6 <= JUDY_PHP_JP_1INDEX_BYTES,
        j1_immed6_fits_jp_1index);
JUDY_PHP_STATIC_ASSERT(cJ1_IMMED7_MAXPOP1 * 7 <= JUDY_PHP_JP_1INDEX_BYTES,
        j1_immed7_fits_jp_1index);
#endif

/* JudyL immediates live in jp_LIndex only (first word holds the value
 * pointer).  IMMED1 is the ZERO-MARGIN pin: cJL_IMMED1_MAXPOP1 one-byte
 * indexes must exactly fill the sizeof(Word_t) - 1 bytes -- if either
 * side of this equality drifts, the layout assumption behind every
 * JudyL immediate is broken. */
JUDY_PHP_STATIC_ASSERT(cJL_IMMED1_MAXPOP1 * 1 == JUDY_PHP_JP_LINDEX_BYTES,
        jl_immed1_exactly_fills_jp_lindex);
JUDY_PHP_STATIC_ASSERT(cJL_IMMED2_MAXPOP1 * 2 <= JUDY_PHP_JP_LINDEX_BYTES,
        jl_immed2_fits_jp_lindex);
JUDY_PHP_STATIC_ASSERT(cJL_IMMED3_MAXPOP1 * 3 <= JUDY_PHP_JP_LINDEX_BYTES,
        jl_immed3_fits_jp_lindex);
#ifdef JU_64BIT
JUDY_PHP_STATIC_ASSERT(cJL_IMMED4_MAXPOP1 * 4 <= JUDY_PHP_JP_LINDEX_BYTES,
        jl_immed4_fits_jp_lindex);
JUDY_PHP_STATIC_ASSERT(cJL_IMMED5_MAXPOP1 * 5 <= JUDY_PHP_JP_LINDEX_BYTES,
        jl_immed5_fits_jp_lindex);
JUDY_PHP_STATIC_ASSERT(cJL_IMMED6_MAXPOP1 * 6 <= JUDY_PHP_JP_LINDEX_BYTES,
        jl_immed6_fits_jp_lindex);
JUDY_PHP_STATIC_ASSERT(cJL_IMMED7_MAXPOP1 * 7 <= JUDY_PHP_JP_LINDEX_BYTES,
        jl_immed7_fits_jp_lindex);
#endif
