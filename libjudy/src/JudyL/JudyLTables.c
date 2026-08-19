// php-judy: pre-generated JudyL size-class tables for the bundled libJudy.
// NOT an upstream Judy-1.0.5 source file -- upstream generates this file at
// build time.  Generated 2026-08-18 by compiling
// src/JudyCommon/JudyTables.c with -DJUDYL -DJU_64BIT -O2 from the pristine
// Judy-1.0.5 import, then running the resulting generator; the output was
// verified byte-identical between arm64 (Apple clang 17) and x86-64
// (gcc 13, Docker).  Everything between this header and the "php-judy:
// compile-time pins" footer is the generator's output, unmodified.
// Regenerate on both architectures and re-verify identity whenever the
// size-class definitions in JudyL.h / JudyPrivate.h change.
// See libjudy/PATCHES.md and issue #142.
//
// Upstream compiles this translation unit with -DJUDYL; define it here so the
// variant headers resolve identically however the file is wired in.
#ifndef JUDYL
#define JUDYL 1
#endif

// @(#) From generation tool: $Revision: 4.37 $ $Source: /judy/src/JudyCommon/JudyTables.c $
//

#include "JudyL.h"
// Leave the malloc() sizes readable in the binary (via strings(1)):
const char * JudyLMallocSizes = "JudyLMallocSizes = 3, 5, 7, 11, 15, 23, 32, 47, 64, Leaf1 = 13";


//	object uses 64 words
//	cJU_BITSPERSUBEXPB = 32
const uint8_t
j__L_BranchBJPPopToWords[cJU_BITSPERSUBEXPB + 1] =
{
	 0,
	 3,  5,  7, 11, 11, 15, 15, 23, 
	23, 23, 23, 32, 32, 32, 32, 32, 
	47, 47, 47, 47, 47, 47, 47, 64, 
	64, 64, 64, 64, 64, 64, 64, 64
};

//	object uses 15 words
//	cJL_LEAF1_MAXPOP1 = 13
const uint8_t
j__L_Leaf1PopToWords[cJL_LEAF1_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  5,  5,  7,  7, 11, 11, 
	11, 15, 15, 15, 15
};
const uint8_t
j__L_Leaf1Offset[cJL_LEAF1_MAXPOP1 + 1] =
{
	 0,
	 1,  1,  1,  1,  1,  1,  2,  2, 
	 2,  2,  2,  2,  2
};

//	object uses 64 words
//	cJL_LEAF2_MAXPOP1 = 51
const uint8_t
j__L_Leaf2PopToWords[cJL_LEAF2_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  5,  5,  7, 11, 11, 11, 
	15, 15, 15, 15, 23, 23, 23, 23, 
	23, 23, 32, 32, 32, 32, 32, 32, 
	32, 47, 47, 47, 47, 47, 47, 47, 
	47, 47, 47, 47, 47, 64, 64, 64, 
	64, 64, 64, 64, 64, 64, 64, 64, 
	64, 64, 64
};
const uint8_t
j__L_Leaf2Offset[cJL_LEAF2_MAXPOP1 + 1] =
{
	 0,
	 1,  1,  1,  1,  2,  3,  3,  3, 
	 3,  3,  3,  3,  5,  5,  5,  5, 
	 5,  5,  7,  7,  7,  7,  7,  7, 
	 7, 10, 10, 10, 10, 10, 10, 10, 
	10, 10, 10, 10, 10, 13, 13, 13, 
	13, 13, 13, 13, 13, 13, 13, 13, 
	13, 13, 13
};

//	object uses 64 words
//	cJL_LEAF3_MAXPOP1 = 46
const uint8_t
j__L_Leaf3PopToWords[cJL_LEAF3_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  5,  7,  7, 11, 11, 11, 
	15, 15, 23, 23, 23, 23, 23, 23, 
	32, 32, 32, 32, 32, 32, 32, 47, 
	47, 47, 47, 47, 47, 47, 47, 47, 
	47, 47, 64, 64, 64, 64, 64, 64, 
	64, 64, 64, 64, 64, 64
};
const uint8_t
j__L_Leaf3Offset[cJL_LEAF3_MAXPOP1 + 1] =
{
	 0,
	 1,  1,  2,  2,  2,  3,  3,  3, 
	 4,  4,  6,  6,  6,  6,  6,  6, 
	 9,  9,  9,  9,  9,  9,  9, 13, 
	13, 13, 13, 13, 13, 13, 13, 13, 
	13, 13, 18, 18, 18, 18, 18, 18, 
	18, 18, 18, 18, 18, 18
};

//	object uses 63 words
//	cJL_LEAF4_MAXPOP1 = 42
const uint8_t
j__L_Leaf4PopToWords[cJL_LEAF4_MAXPOP1 + 1] =
{
	 0,
	 3,  3,  5,  7, 11, 11, 11, 15, 
	15, 15, 23, 23, 23, 23, 23, 32, 
	32, 32, 32, 32, 32, 47, 47, 47, 
	47, 47, 47, 47, 47, 47, 47, 63, 
	63, 63, 63, 63, 63, 63, 63, 63, 
	63, 63
};
const uint8_t
j__L_Leaf4Offset[cJL_LEAF4_MAXPOP1 + 1] =
{
	 0,
	 1,  1,  2,  2,  4,  4,  4,  5, 
	 5,  5,  8,  8,  8,  8,  8, 11, 
	11, 11, 11, 11, 11, 16, 16, 16, 
	16, 16, 16, 16, 16, 16, 16, 21, 
	21, 21, 21, 21, 21, 21, 21, 21, 
	21, 21
};

//	object uses 64 words
//	cJL_LEAF5_MAXPOP1 = 39
const uint8_t
j__L_Leaf5PopToWords[cJL_LEAF5_MAXPOP1 + 1] =
{
	 0,
	 3,  5,  5,  7, 11, 11, 15, 15, 
	15, 23, 23, 23, 23, 23, 32, 32, 
	32, 32, 32, 47, 47, 47, 47, 47, 
	47, 47, 47, 47, 64, 64, 64, 64, 
	64, 64, 64, 64, 64, 64, 64
};
const uint8_t
j__L_Leaf5Offset[cJL_LEAF5_MAXPOP1 + 1] =
{
	 0,
	 2,  2,  2,  3,  4,  4,  6,  6, 
	 6,  9,  9,  9,  9,  9, 12, 12, 
	12, 12, 12, 18, 18, 18, 18, 18, 
	18, 18, 18, 18, 25, 25, 25, 25, 
	25, 25, 25, 25, 25, 25, 25
};

//	object uses 63 words
//	cJL_LEAF6_MAXPOP1 = 36
const uint8_t
j__L_Leaf6PopToWords[cJL_LEAF6_MAXPOP1 + 1] =
{
	 0,
	 3,  5,  7,  7, 11, 11, 15, 15, 
	23, 23, 23, 23, 23, 32, 32, 32, 
	32, 32, 47, 47, 47, 47, 47, 47, 
	47, 47, 63, 63, 63, 63, 63, 63, 
	63, 63, 63, 63
};
const uint8_t
j__L_Leaf6Offset[cJL_LEAF6_MAXPOP1 + 1] =
{
	 0,
	 1,  3,  3,  3,  5,  5,  6,  6, 
	10, 10, 10, 10, 10, 14, 14, 14, 
	14, 14, 20, 20, 20, 20, 20, 20, 
	20, 20, 27, 27, 27, 27, 27, 27, 
	27, 27, 27, 27
};

//	object uses 64 words
//	cJL_LEAF7_MAXPOP1 = 34
const uint8_t
j__L_Leaf7PopToWords[cJL_LEAF7_MAXPOP1 + 1] =
{
	 0,
	 3,  5,  7, 11, 11, 15, 15, 15, 
	23, 23, 23, 23, 32, 32, 32, 32, 
	32, 47, 47, 47, 47, 47, 47, 47, 
	47, 64, 64, 64, 64, 64, 64, 64, 
	64, 64
};
const uint8_t
j__L_Leaf7Offset[cJL_LEAF7_MAXPOP1 + 1] =
{
	 0,
	 1,  3,  3,  5,  5,  7,  7,  7, 
	11, 11, 11, 11, 15, 15, 15, 15, 
	15, 22, 22, 22, 22, 22, 22, 22, 
	22, 30, 30, 30, 30, 30, 30, 30, 
	30, 30
};

//	object uses 63 words
//	cJL_LEAFW_MAXPOP1 = 31
const uint8_t
j__L_LeafWPopToWords[cJL_LEAFW_MAXPOP1 + 1] =
{
	 0,
	 3,  5,  7, 11, 11, 15, 15, 23, 
	23, 23, 23, 32, 32, 32, 32, 47, 
	47, 47, 47, 47, 47, 47, 47, 63, 
	63, 63, 63, 63, 63, 63, 63
};
const uint8_t
j__L_LeafWOffset[cJL_LEAFW_MAXPOP1 + 1] =
{
	 0,
	 2,  3,  4,  6,  6,  8,  8, 12, 
	12, 12, 12, 16, 16, 16, 16, 24, 
	24, 24, 24, 24, 24, 24, 24, 32, 
	32, 32, 32, 32, 32, 32, 32
};

//	object uses 64 words
//	cJU_BITSPERSUBEXPL = 64
const uint8_t
j__L_LeafVPopToWords[cJU_BITSPERSUBEXPL + 1] =
{
	 0,
	 3,  3,  3,  5,  5,  7,  7, 11, 
	11, 11, 11, 15, 15, 15, 15, 23, 
	23, 23, 23, 23, 23, 23, 23, 32, 
	32, 32, 32, 32, 32, 32, 32, 32, 
	47, 47, 47, 47, 47, 47, 47, 47, 
	47, 47, 47, 47, 47, 47, 47, 64, 
	64, 64, 64, 64, 64, 64, 64, 64, 
	64, 64, 64, 64, 64, 64, 64, 64
};

// php-judy: compile-time pins.  The initializers above were baked in by the
// generator against the header constants named in each dimension; C silently
// accepts an initializer list SHORTER than the declared dimension, so a
// header drift would otherwise ship half-initialized tables.  These pins
// fail the build instead, forcing regeneration.  The immediate-capacity
// (cJL_IMMED*_MAXPOP1) pins are deliberately deferred to the Stage 2 P1
// patch (see Judy1Tables.c for why).
#define JUDY_PHP_TABLES_ASSERT(cond, name) \
        typedef char judy_php_tables_assert_ ## name [(cond) ? 1 : -1]

// This file was generated under JU_64BIT: a 32-bit build must regenerate
// it, not reuse it.  The #error states the cause in one line; the pin below
// is the belt to its suspenders, and still catches a build that forces
// JU_64BIT on despite a 32-bit word.  config.m4 and config.w32 both refuse a
// 32-bit target before reaching this file -- these two are the backstop for
// any other build system, and for a hand-rolled compile of these sources.
#ifndef JU_64BIT
#error "php-judy: these pre-generated libJudy tables require a 64-bit build (-DJU_64BIT). Regenerate them for 32-bit words, or link a system libJudy instead (--with-judy=DIR)."
#endif
JUDY_PHP_TABLES_ASSERT(sizeof(Word_t) == 8, word_t_is_8_bytes);

// Dimensions the generator baked into the initializer lists above.
JUDY_PHP_TABLES_ASSERT(cJU_BITSPERSUBEXPB == 32, branchb_subexpanse_is_32);
JUDY_PHP_TABLES_ASSERT(cJU_BITSPERSUBEXPL == 64, leafv_subexpanse_is_64);
JUDY_PHP_TABLES_ASSERT(cJL_LEAF1_MAXPOP1 ==  13, leaf1_maxpop1_is_13);
JUDY_PHP_TABLES_ASSERT(cJL_LEAF2_MAXPOP1 ==  51, leaf2_maxpop1_is_51);
JUDY_PHP_TABLES_ASSERT(cJL_LEAF3_MAXPOP1 ==  46, leaf3_maxpop1_is_46);
JUDY_PHP_TABLES_ASSERT(cJL_LEAF4_MAXPOP1 ==  42, leaf4_maxpop1_is_42);
JUDY_PHP_TABLES_ASSERT(cJL_LEAF5_MAXPOP1 ==  39, leaf5_maxpop1_is_39);
JUDY_PHP_TABLES_ASSERT(cJL_LEAF6_MAXPOP1 ==  36, leaf6_maxpop1_is_36);
JUDY_PHP_TABLES_ASSERT(cJL_LEAF7_MAXPOP1 ==  34, leaf7_maxpop1_is_34);
JUDY_PHP_TABLES_ASSERT(cJL_LEAFW_MAXPOP1 ==  31, leafw_maxpop1_is_31);

// The word sizes above (3, 5, 7, 11, 15, 23, 32, 47, 64) come from
// ALLOCSIZES in JudyL.h: nine sizes plus the generator's TERMINATOR (999).
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
