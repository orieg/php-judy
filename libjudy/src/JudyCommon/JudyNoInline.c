/* php-judy addition -- NOT an upstream Judy-1.0.5 file (patch P7, see
 * libjudy/PATCHES.md, issues #127/#142).
 *
 * Out-of-line definitions for the JU_NOINLINE build knob.  JudyPrivate.h
 * declares j__udyCountBits{B,L} and j__udySearchLeaf{1..7,W} as extern
 * under -DJU_NOINLINE (a profiling aid: out-of-line copies are visible to
 * profilers and breakpoints), but upstream ships no definitions -- the
 * knob could never link.  The bodies below are the header's own inline
 * versions (JudyPrivate.h), verbatim: the bit-counting arms and the
 * SEARCHLEAF* macros, which are defined by the header regardless of
 * JU_NOINLINE.
 *
 * Updated for php-judy on 2026-08-18 (patch O1, see libjudy/PATCHES.md,
 * issue #142): j__udyCountBits{B,L} mirror the header's new hardware-
 * popcount arms (__POPCNT__ / aarch64 / MSVC x64), so a JU_NOINLINE
 * profiling build measures the same algorithm production builds run;
 * builds selecting none of those arms keep the portable folds verbatim.
 *
 * This TU is variant-free (no JUDY1/JUDYL): all of these routines are
 * shared between the variants, which is why upstream gives them no 1/L
 * prefix.  Without -DJU_NOINLINE it compiles to nothing (the placeholder
 * typedef keeps the translation unit non-empty for ISO C).
 */

typedef int judy_php_noinline_placeholder;

#ifdef JU_NOINLINE

#include "JudyPrivate.h"

#if defined(_MSC_VER) && defined(_M_X64)
#include <intrin.h>
#endif

// ****************************************************************************
// __ J U D Y   C O U N T   B I T S   B
//
// Return the number of bits set in "Word", for a bitmap branch.
//
// Note:  Bitmap branches have maximum bitmap size = 32 bits.

BITMAPB_t j__udyCountBitsB(BITMAPB_t word)
{
#if defined(__POPCNT__) || defined(__aarch64__)
        return((BITMAPB_t)__builtin_popcount((uint32_t)word));
#elif defined(_MSC_VER) && defined(_M_X64)
        return((BITMAPB_t)__popcnt((unsigned int)word));
#else
        word = (word & 0x55555555) + ((word & 0xAAAAAAAA) >>  1);
        word = (word & 0x33333333) + ((word & 0xCCCCCCCC) >>  2);
        word = (word & 0x0F0F0F0F) + ((word & 0xF0F0F0F0) >>  4); // >= 8 bits.
#if defined(BITMAP_BRANCH16x16) || defined(BITMAP_BRANCH32x8)
        word = (word & 0x00FF00FF) + ((word & 0xFF00FF00) >>  8); // >= 16 bits.
#endif

#ifdef BITMAP_BRANCH32x8
        word = (word & 0x0000FFFF) + ((word & 0xFFFF0000) >> 16); // >= 32 bits.
#endif
        return(word);
#endif // portable folds

} // j__udyCountBitsB()


// ****************************************************************************
// __ J U D Y   C O U N T   B I T S   L
//
// Return the number of bits set in "Word", for a bitmap leaf.
//
// Note:  Need both 32-bit and 64-bit versions of j__udyCountBitsL() because
// bitmap leaves can have 64-bit bitmaps.

BITMAPL_t j__udyCountBitsL(BITMAPL_t word)
{
#if defined(__POPCNT__) || defined(__aarch64__)
        return((BITMAPL_t)__builtin_popcountll((uint64_t)word));
#elif defined(_MSC_VER) && defined(_M_X64)
        return((BITMAPL_t)__popcnt64((unsigned __int64)word));
#else
#ifndef JU_64BIT

        word = (word & 0x55555555) + ((word & 0xAAAAAAAA) >>  1);
        word = (word & 0x33333333) + ((word & 0xCCCCCCCC) >>  2);
        word = (word & 0x0F0F0F0F) + ((word & 0xF0F0F0F0) >>  4); // >= 8 bits.
#if defined(BITMAP_LEAF16x16) || defined(BITMAP_LEAF32x8)
        word = (word & 0x00FF00FF) + ((word & 0xFF00FF00) >>  8); // >= 16 bits.
#endif
#ifdef BITMAP_LEAF32x8
        word = (word & 0x0000FFFF) + ((word & 0xFFFF0000) >> 16); // >= 32 bits.
#endif

#else // JU_64BIT

        word = (word & 0x5555555555555555) + ((word & 0xAAAAAAAAAAAAAAAA) >> 1);
        word = (word & 0x3333333333333333) + ((word & 0xCCCCCCCCCCCCCCCC) >> 2);
        word = (word & 0x0F0F0F0F0F0F0F0F) + ((word & 0xF0F0F0F0F0F0F0F0) >> 4);
#if defined(BITMAP_LEAF16x16) || defined(BITMAP_LEAF32x8) || defined(BITMAP_LEAF64x4)
        word = (word & 0x00FF00FF00FF00FF) + ((word & 0xFF00FF00FF00FF00) >> 8);
#endif
#if defined(BITMAP_LEAF32x8) || defined(BITMAP_LEAF64x4)
        word = (word & 0x0000FFFF0000FFFF) + ((word & 0xFFFF0000FFFF0000) >>16);
#endif
#ifdef BITMAP_LEAF64x4
        word = (word & 0x00000000FFFFFFFF) + ((word & 0xFFFFFFFF00000000) >>32);
#endif
#endif // JU_64BIT

        return(word);
#endif // portable folds

} // j__udyCountBitsL()


// ****************************************************************************
// Leaf search routines -- the SEARCHLEAF* macro expansions the header
// otherwise emits as static inline functions:

int j__udySearchLeaf1(Pjll_t Pjll, Word_t LeafPop1, Word_t Index)
{ SEARCHLEAFNATIVE(uint8_t,  Pjll, LeafPop1, Index); }

int j__udySearchLeaf2(Pjll_t Pjll, Word_t LeafPop1, Word_t Index)
{ SEARCHLEAFNATIVE(uint16_t, Pjll, LeafPop1, Index); }

int j__udySearchLeaf3(Pjll_t Pjll, Word_t LeafPop1, Word_t Index)
{ SEARCHLEAFNONNAT(Pjll, LeafPop1, Index, 3, JU_COPY3_PINDEX_TO_LONG); }

#ifdef JU_64BIT

int j__udySearchLeaf4(Pjll_t Pjll, Word_t LeafPop1, Word_t Index)
{ SEARCHLEAFNATIVE(uint32_t, Pjll, LeafPop1, Index); }

int j__udySearchLeaf5(Pjll_t Pjll, Word_t LeafPop1, Word_t Index)
{ SEARCHLEAFNONNAT(Pjll, LeafPop1, Index, 5, JU_COPY5_PINDEX_TO_LONG); }

int j__udySearchLeaf6(Pjll_t Pjll, Word_t LeafPop1, Word_t Index)
{ SEARCHLEAFNONNAT(Pjll, LeafPop1, Index, 6, JU_COPY6_PINDEX_TO_LONG); }

int j__udySearchLeaf7(Pjll_t Pjll, Word_t LeafPop1, Word_t Index)
{ SEARCHLEAFNONNAT(Pjll, LeafPop1, Index, 7, JU_COPY7_PINDEX_TO_LONG); }

#endif // JU_64BIT

int j__udySearchLeafW(Pjlw_t Pjlw, Word_t LeafPop1, Word_t Index)
{ SEARCHLEAFNATIVE(Word_t, Pjlw, LeafPop1, Index); }

#endif // JU_NOINLINE
