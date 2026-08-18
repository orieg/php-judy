/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compile-time environment pins for the bundled libJudy.  This TU is
 * variant-free (no JUDY1/JUDYL): it checks only what must hold before any
 * variant compiles.  Table-dimension pins live in Judy1Tables.c /
 * JudyLTables.c next to the data they guard; the immediate-capacity pins
 * are deferred to the Stage 2 P1 patch (they fail on pristine sources --
 * that is the #131 bug). */

#include "Judy.h"

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
