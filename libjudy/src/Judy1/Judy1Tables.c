// php-judy: pre-generated Judy1 size-class tables for the bundled libJudy.
// NOT an upstream Judy-1.0.5 source file -- upstream generates this file at
// build time.  Generated 2026-08-18 by compiling
// src/JudyCommon/JudyTables.c with -DJUDY1 -DJU_64BIT -O2 from the pristine
// Judy-1.0.5 import, then running the resulting generator; the output was
// verified byte-identical between arm64 (Apple clang 17) and x86-64
// (gcc 13, Docker).  Everything between this header and the "php-judy:
// compile-time pins" footer is the generator's output, unmodified.
// Regenerate on both architectures and re-verify identity whenever the
// size-class definitions in Judy1.h / JudyPrivate.h change.
// See libjudy/PATCHES.md and issue #142.
//
// Upstream compiles this translation unit with -DJUDY1; define it here so the
// variant headers resolve identically however the file is wired in.
#ifndef JUDY1
#define JUDY1 1
#endif

// @(#) From generation tool: $Revision: 4.37 $ $Source: /judy/src/JudyCommon/JudyTables.c $
//

#include "Judy1.h"
// Leave the malloc() sizes readable in the binary (via strings(1)):
const char * Judy1MallocSizes = "Judy1MallocSizes = 3, 5, 7, 11, 15, 23, 32, 47, 64,";


//	object uses 64 words
//	cJU_BITSPERSUBEXPB = 32
const uint8_t
j__1_BranchBJPPopToWords[cJU_BITSPERSUBEXPB + 1] =
{
	 0,
	 3,  5,  7, 11, 11, 15, 15, 23, 
	23, 23, 23, 32, 32, 32, 32, 32, 
	47, 47, 47, 47, 47, 47, 47, 64, 
	64, 64, 64, 64, 64, 64, 64, 64
};

//	object uses 32 words
//	cJ1_LEAF2_MAXPOP1 = 128
const uint8_t
j__1_Leaf2PopToWords[cJ1_LEAF2_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  3,  3,  3,  3,  3,  3, 
	 3,  3,  3,  3,  5,  5,  5,  5, 
	 5,  5,  5,  5,  7,  7,  7,  7, 
	 7,  7,  7,  7, 11, 11, 11, 11, 
	11, 11, 11, 11, 11, 11, 11, 11, 
	11, 11, 11, 11, 15, 15, 15, 15, 
	15, 15, 15, 15, 15, 15, 15, 15, 
	15, 15, 15, 15, 23, 23, 23, 23, 
	23, 23, 23, 23, 23, 23, 23, 23, 
	23, 23, 23, 23, 23, 23, 23, 23, 
	23, 23, 23, 23, 23, 23, 23, 23, 
	23, 23, 23, 23, 32, 32, 32, 32, 
	32, 32, 32, 32, 32, 32, 32, 32, 
	32, 32, 32, 32, 32, 32, 32, 32, 
	32, 32, 32, 32, 32, 32, 32, 32, 
	32, 32, 32, 32, 32, 32, 32, 32
};

//	object uses 32 words
//	cJ1_LEAF3_MAXPOP1 = 85
const uint8_t
j__1_Leaf3PopToWords[cJ1_LEAF3_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  3,  3,  3,  3,  3,  3, 
	 5,  5,  5,  5,  5,  7,  7,  7, 
	 7,  7, 11, 11, 11, 11, 11, 11, 
	11, 11, 11, 11, 11, 15, 15, 15, 
	15, 15, 15, 15, 15, 15, 15, 15, 
	23, 23, 23, 23, 23, 23, 23, 23, 
	23, 23, 23, 23, 23, 23, 23, 23, 
	23, 23, 23, 23, 23, 32, 32, 32, 
	32, 32, 32, 32, 32, 32, 32, 32, 
	32, 32, 32, 32, 32, 32, 32, 32, 
	32, 32, 32, 32, 32
};

//	object uses 32 words
//	cJ1_LEAF4_MAXPOP1 = 64
const uint8_t
j__1_Leaf4PopToWords[cJ1_LEAF4_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  3,  3,  3,  3,  5,  5, 
	 5,  5,  7,  7,  7,  7, 11, 11, 
	11, 11, 11, 11, 11, 11, 15, 15, 
	15, 15, 15, 15, 15, 15, 23, 23, 
	23, 23, 23, 23, 23, 23, 23, 23, 
	23, 23, 23, 23, 23, 23, 32, 32, 
	32, 32, 32, 32, 32, 32, 32, 32, 
	32, 32, 32, 32, 32, 32, 32, 32
};

//	object uses 32 words
//	cJ1_LEAF5_MAXPOP1 = 51
const uint8_t
j__1_Leaf5PopToWords[cJ1_LEAF5_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  3,  3,  5,  5,  5,  5, 
	 7,  7,  7, 11, 11, 11, 11, 11, 
	11, 15, 15, 15, 15, 15, 15, 15, 
	23, 23, 23, 23, 23, 23, 23, 23, 
	23, 23, 23, 23, 32, 32, 32, 32, 
	32, 32, 32, 32, 32, 32, 32, 32, 
	32, 32, 32
};

//	object uses 32 words
//	cJ1_LEAF6_MAXPOP1 = 42
const uint8_t
j__1_Leaf6PopToWords[cJ1_LEAF6_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  3,  3,  5,  5,  7,  7, 
	 7, 11, 11, 11, 11, 11, 15, 15, 
	15, 15, 15, 15, 23, 23, 23, 23, 
	23, 23, 23, 23, 23, 23, 32, 32, 
	32, 32, 32, 32, 32, 32, 32, 32, 
	32, 32
};

//	object uses 32 words
//	cJ1_LEAF7_MAXPOP1 = 36
const uint8_t
j__1_Leaf7PopToWords[cJ1_LEAF7_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  3,  5,  5,  7,  7,  7, 
	11, 11, 11, 11, 15, 15, 15, 15, 
	15, 23, 23, 23, 23, 23, 23, 23, 
	23, 23, 32, 32, 32, 32, 32, 32, 
	32, 32, 32, 32
};

//	object uses 32 words
//	cJ1_LEAFW_MAXPOP1 = 31
const uint8_t
j__1_LeafWPopToWords[cJ1_LEAFW_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  5,  5,  7,  7, 11, 11, 
	11, 11, 15, 15, 15, 15, 23, 23, 
	23, 23, 23, 23, 23, 23, 32, 32, 
	32, 32, 32, 32, 32, 32, 32
};

// php-judy: compile-time pins.  The initializers above were baked in by the
// generator against the header constants named in each dimension; C silently
// accepts an initializer list SHORTER than the declared dimension, so a
// header drift would otherwise ship half-initialized tables.  These pins
// fail the build instead, forcing regeneration.  The immediate-capacity
// (cJ1_IMMED*_MAXPOP1) pins are deliberately deferred to the Stage 2 P1
// patch: on pristine Judy-1.0.5 sources they FAIL (cJ1_IMMED1_MAXPOP1 = 15
// does not fit jp_1Index -- that is the #131 bug), so they can only be
// added together with the fix they guard.
#define JUDY_PHP_TABLES_ASSERT(cond, name) \
        typedef char judy_php_tables_assert_ ## name [(cond) ? 1 : -1]

// This file was generated under JU_64BIT: a 32-bit build must regenerate
// it, not reuse it.
JUDY_PHP_TABLES_ASSERT(sizeof(Word_t) == 8, word_t_is_8_bytes);

// Dimensions the generator baked into the initializer lists above.
JUDY_PHP_TABLES_ASSERT(cJU_BITSPERSUBEXPB == 32, branchb_subexpanse_is_32);
JUDY_PHP_TABLES_ASSERT(cJ1_LEAF2_MAXPOP1 == 128, leaf2_maxpop1_is_128);
JUDY_PHP_TABLES_ASSERT(cJ1_LEAF3_MAXPOP1 ==  85, leaf3_maxpop1_is_85);
JUDY_PHP_TABLES_ASSERT(cJ1_LEAF4_MAXPOP1 ==  64, leaf4_maxpop1_is_64);
JUDY_PHP_TABLES_ASSERT(cJ1_LEAF5_MAXPOP1 ==  51, leaf5_maxpop1_is_51);
JUDY_PHP_TABLES_ASSERT(cJ1_LEAF6_MAXPOP1 ==  42, leaf6_maxpop1_is_42);
JUDY_PHP_TABLES_ASSERT(cJ1_LEAF7_MAXPOP1 ==  36, leaf7_maxpop1_is_36);
JUDY_PHP_TABLES_ASSERT(cJ1_LEAFW_MAXPOP1 ==  31, leafw_maxpop1_is_31);

// The word sizes above (3, 5, 7, 11, 15, 23, 32, 47, 64) come from
// ALLOCSIZES in Judy1.h: nine sizes plus the generator's TERMINATOR (999).
// Element values cannot be checked at compile time; pin the count.
#define TERMINATOR 999 /* JudyTables.c's TERMINATOR */
#if defined(__GNUC__)
__attribute__((unused))
#endif
static const int judy_php_alloc_sizes_pin[] = ALLOCSIZES;
#undef TERMINATOR
JUDY_PHP_TABLES_ASSERT(
        sizeof(judy_php_alloc_sizes_pin) / sizeof(judy_php_alloc_sizes_pin[0])
                == 10,
        allocsizes_is_9_sizes_plus_terminator);
