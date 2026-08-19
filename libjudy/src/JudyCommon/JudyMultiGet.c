// php-judy addition -- NOT an upstream Judy-1.0.5 file (patch O5, see
// libjudy/PATCHES.md, issue #142).  Derived from JudyGet.c's JudyLGet
// dispatch; distributed under the same license as the rest of this
// subtree:
//
// This program is free software; you can redistribute it and/or modify it
// under the term of the GNU Lesser General Public License as published by the
// Free Software Foundation; either version 2 of the License, or (at your
// option) any later version.
//
// This program is distributed in the hope that it will be useful, but WITHOUT
// ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
// FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Lesser General Public License
// for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with this program; if not, write to the Free Software Foundation,
// Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
// _________________
//
// JudyLMultiGet(): batched JudyLGet over an array of independent keys,
// software-pipelined in the AMAC style (Kocberber et al., "Asynchronous
// Memory Access Chaining", VLDB 2015) so that up to cJL_MULTIGET_LANES
// independent cache misses are in flight at once instead of one.
//
// Each in-flight lookup is a LANE holding one key's descend state.  A lane
// advances one "memory epoch" per step: it consumes the node whose cache
// line it prefetched on its previous step, computes, issues the prefetch
// for its next dependent line, and yields to the other lanes.  The descend
// logic per node type is JudyGet.c's, restructured from a loop into a
// resumable state machine; the leaf searches call the same
// j__udySearchLeaf*() inlines JudyLGet compiles, so per-node compute is
// identical -- only the schedule differs.
//
// Phasing (measured on the #142 O5 gate, see research/libjudy-modernization/
// FINDINGS.md 11.5 -- deviating from this collapsed the win in ablation):
//   - BRANCH_U:  child JP address is arithmetic on the fetched JP; compute
//     and prefetch the child, one epoch per level.
//   - BRANCH_L / BRANCH_B: the small branch struct is demand-loaded in the
//     SAME epoch and only the chosen child JP is prefetched ("branches
//     one-phase").  Giving these their own epoch collapsed bitmap-branch
//     corpora to x0.65 with negative lane scaling.
//   - LEAF1..7:  prefetch the packed key area (up to 4 lines), search it
//     next epoch ("leaves two-phase").
//   - LEAF_B1:   prefetch the subexpanse bitmap + value-pointer words,
//     resolve next epoch.
//   - IMMED_*:   decoded from the JP itself; terminal, no dependent fetch.
//
// Correctness posture: every JP type of the JudyL/JU_64BIT GET dispatch is
// covered, including the DCD narrow-pointer checks on every level where
// JudyGet.c performs them (their absence returns wrong answers for absent
// keys).  Anything unrecognized (corrupt tree) falls back to a per-key
// JudyLGet() call, which reproduces JudyLGet's error behavior exactly --
// there is no silent-wrong-answer path.  Batched and serial answers are
// pointer-identical by construction and verified by the differential
// fuzzer's multiget mode (research/differential-fuzz/).
//
// Tiny trees and tiny batches are probed serially: with everything cache-
// resident there is nothing to overlap and the lane machinery measured
// x0.86-0.95 (gate record).  The thresholds are compile-time tunables so
// the fuzzer can force the pipelined path onto arbitrarily small trees.
//
// This TU is JudyL-only: the API returns value-area pointers.  (A Judy1
// MultiTest variant would need none of the value-area code; it has no
// caller today and is deliberately not provided.)
//
// Compile with -DJUDYL only.

#if (! defined(JUDYL) || defined(JUDY1))
#error:  JudyMultiGet.c compiles only as the JUDYL variant (-DJUDYL).
#endif

#include "JudyL.h"
#include "JudyPrivate1L.h"

#ifndef JU_64BIT
#error:  JudyMultiGet.c supports only JU_64BIT builds (matches the bundled tables).
#endif

// Public prototype (kept in sync with libjudy/src/JudyMultiGet.h, which
// callers outside the library include; this TU deliberately does not
// depend on that header's existence).
extern Word_t JudyLMultiGet(Pcvoid_t PArray, const Word_t *PIndex,
                            PPvoid_t *PPValue, Word_t Count);

// ****************************************************************************
// TUNABLES (compile-time; defaults are the #142 O5 gate's measured optima):

#ifndef cJL_MULTIGET_LANES              // in-flight lookups; 16 measured best,
#define cJL_MULTIGET_LANES 16           // 32 only marginally better DRAM-bound.
#endif

#if (cJL_MULTIGET_LANES < 1) || (cJL_MULTIGET_LANES > 64)
#error:  cJL_MULTIGET_LANES must be in 1..64.
#endif

#ifndef cJL_MULTIGET_SERIAL_POP1        // arrays below this population probe
#define cJL_MULTIGET_SERIAL_POP1 262144 // serially: cache-resident trees have
#endif                                  // nothing to overlap and the lanes
                                        // measured x0.46-0.96 at pop 16K-64K;
                                        // 262144 is the smallest measured
                                        // population where every corpus shape
                                        // cleared CI-low > 1.0 (derivation in
                                        // PATCHES.md O5, crossover sweep).

#ifndef cJL_MULTIGET_SERIAL_COUNT       // batches shorter than this probe
#define cJL_MULTIGET_SERIAL_COUNT 4     // serially (pipeline fill cost).
#endif

// ****************************************************************************
// PREFETCH: advisory; expanding to nothing is correct (only slower).

#if defined(_MSC_VER) && ! defined(__clang__)
#if defined(_M_ARM64)
#include <intrin.h>
#define JL_MG_PREFETCH(Addr) __prefetch((const void *)(Addr))
#else
#include <xmmintrin.h>
#define JL_MG_PREFETCH(Addr) _mm_prefetch((const char *)(Addr), _MM_HINT_T0)
#endif
#elif defined(__GNUC__) || defined(__clang__)
#define JL_MG_PREFETCH(Addr) __builtin_prefetch((const void *)(Addr), 0, 3)
#else
#define JL_MG_PREFETCH(Addr) ((void)(Addr))
#endif

// Prefetch the first cache lines of a dependent region (leaf key areas;
// largest is LEAF7 at max pop1 34 * 7 = 238 bytes = 4 lines):

static void jl_mg_prefetch_range(const void *Addr, Word_t Bytes)
{
        const char *P = (const char *)Addr;

        JL_MG_PREFETCH(P);
        if (Bytes >  64) JL_MG_PREFETCH(P +  64);
        if (Bytes > 128) JL_MG_PREFETCH(P + 128);
        if (Bytes > 192) JL_MG_PREFETCH(P + 192);
}

// ****************************************************************************
// LANE STATE:
//
// One lane = one key's in-flight descend.  Kept to 48 bytes deliberately
// (16 lanes = 768 bytes, L1-resident beside the caller's buffers): a fatter
// first cut measured ~15% slower at 16 lanes on the gate prototype.

enum jl_mg_state
{
        JL_MG_JP = 0,           // mg_Pjp's line has arrived; dispatch its type.
        JL_MG_LEAF,             // linear-leaf key area arriving; search next.
        JL_MG_LEAFB1,           // bitmap-leaf subexpanse words arriving.
        JL_MG_DONE              // result written to PPValue[mg_Slot].
};

typedef struct J_L_MULTIGET_LANE
{
        Word_t  mg_Index;       // key being looked up.
        Pjp_t   mg_Pjp;         // current JP (JL_MG_JP).
        Word_t  mg_Pop1;        // linear-leaf population (JL_MG_LEAF).
        union {                 // the one dependent node being waited on:
            Pjll_t mg_Pjll;     //   linear leaf (JL_MG_LEAF).
            Pjlb_t mg_Pjlb;     //   bitmap leaf (JL_MG_LEAFB1).
        } mg_u;
        Word_t  mg_Slot;        // output slot in PPValue[].
        uint8_t mg_State;       // enum jl_mg_state.
        uint8_t mg_Level;       // leaf level 1..7 (JL_MG_LEAF).
        uint8_t mg_Digit;       // decoded digit (JL_MG_LEAFB1).
        uint8_t mg_Subexp;      // bitmap subexpanse (JL_MG_LEAFB1).
        uint8_t mg_Fallback;    // unrecognized JP type; redo via JudyLGet().
} jl_mg_lane_t;

// Terminal outcomes; write the result directly and mark the lane done:

#define JL_MG_MISS(Lane,PPValue)                                        \
        {                                                               \
            (PPValue)[(Lane)->mg_Slot] = (PPvoid_t) NULL;               \
            (Lane)->mg_State = JL_MG_DONE;                              \
            return;                                                     \
        }

#define JL_MG_HIT(Lane,PPValue,Pv)                                      \
        {                                                               \
            (PPValue)[(Lane)->mg_Slot] = (PPvoid_t) (Pv);               \
            (Lane)->mg_State = JL_MG_DONE;                              \
            return;                                                     \
        }

// ****************************************************************************
// J L   M G   S T E P
//
// Advance one lane by one memory epoch.  The dispatch mirrors JudyGet.c's
// switch case-for-case (same DCD checks at the same levels, same search
// inlines); see that file for the per-type commentary.

static void jl_mg_step(jl_mg_lane_t *Lane, PPvoid_t *PPValue)
{
        Word_t Index = Lane->mg_Index;

        switch (Lane->mg_State)
        {

        case JL_MG_JP:
        {
            Pjp_t   Pjp = Lane->mg_Pjp;
            uint8_t Digit;

            switch (JU_JPTYPE(Pjp))
            {

// Unrecognized types (including case 0 == corrupt): resolved by a per-key
// JudyLGet() after the lane drains, which also reproduces its error
// (PPJERR) behavior:

            default:
            case 0:
                Lane->mg_Fallback = 1;
                JL_MG_MISS(Lane, PPValue);

// JPNULL*: legitimate in a BranchU; terminal miss:

            case cJU_JPNULL1:
            case cJU_JPNULL2:
            case cJU_JPNULL3:
            case cJU_JPNULL4:
            case cJU_JPNULL5:
            case cJU_JPNULL6:
            case cJU_JPNULL7:
                JL_MG_MISS(Lane, PPValue);

// JPBRANCH_L*: one-phase (see phasing note in the file header) -- the
// small struct is demand-loaded now; only the chosen child is prefetched:

            case cJU_JPBRANCH_L2:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 2)) JL_MG_MISS(Lane, PPValue);
                Digit = JU_DIGITATSTATE(Index, 2);
                goto JudyMGBranchL;

            case cJU_JPBRANCH_L3:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 3)) JL_MG_MISS(Lane, PPValue);
                Digit = JU_DIGITATSTATE(Index, 3);
                goto JudyMGBranchL;

            case cJU_JPBRANCH_L4:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 4)) JL_MG_MISS(Lane, PPValue);
                Digit = JU_DIGITATSTATE(Index, 4);
                goto JudyMGBranchL;

            case cJU_JPBRANCH_L5:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 5)) JL_MG_MISS(Lane, PPValue);
                Digit = JU_DIGITATSTATE(Index, 5);
                goto JudyMGBranchL;

            case cJU_JPBRANCH_L6:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 6)) JL_MG_MISS(Lane, PPValue);
                Digit = JU_DIGITATSTATE(Index, 6);
                goto JudyMGBranchL;

            case cJU_JPBRANCH_L7:
                // JU_DCDNOTMATCHINDEX() would be a no-op.
                Digit = JU_DIGITATSTATE(Index, 7);
                goto JudyMGBranchL;

            case cJU_JPBRANCH_L:
            {
                Pjbl_t Pjbl;
                int    posidx;

                Digit = JU_DIGITATSTATE(Index, cJU_ROOTSTATE);

JudyMGBranchL:
                Pjbl = P_JBL(Pjp->jp_Addr);

                posidx = 0;

                do {
                    if (Pjbl->jbl_Expanse[posidx] == Digit)
                    {
                        Lane->mg_Pjp = Pjbl->jbl_jp + posidx;
                        JL_MG_PREFETCH(Lane->mg_Pjp);
                        return;                 // next epoch: dispatch child.
                    }
                } while (++posidx != Pjbl->jbl_NumJPs);

                JL_MG_MISS(Lane, PPValue);
            }

// JPBRANCH_B*: one-phase, same reasoning as BRANCH_L:

            case cJU_JPBRANCH_B2:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 2)) JL_MG_MISS(Lane, PPValue);
                Digit = JU_DIGITATSTATE(Index, 2);
                goto JudyMGBranchB;

            case cJU_JPBRANCH_B3:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 3)) JL_MG_MISS(Lane, PPValue);
                Digit = JU_DIGITATSTATE(Index, 3);
                goto JudyMGBranchB;

            case cJU_JPBRANCH_B4:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 4)) JL_MG_MISS(Lane, PPValue);
                Digit = JU_DIGITATSTATE(Index, 4);
                goto JudyMGBranchB;

            case cJU_JPBRANCH_B5:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 5)) JL_MG_MISS(Lane, PPValue);
                Digit = JU_DIGITATSTATE(Index, 5);
                goto JudyMGBranchB;

            case cJU_JPBRANCH_B6:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 6)) JL_MG_MISS(Lane, PPValue);
                Digit = JU_DIGITATSTATE(Index, 6);
                goto JudyMGBranchB;

            case cJU_JPBRANCH_B7:
                // JU_DCDNOTMATCHINDEX() would be a no-op.
                Digit = JU_DIGITATSTATE(Index, 7);
                goto JudyMGBranchB;

            case cJU_JPBRANCH_B:
            {
                Pjbb_t    Pjbb;
                Word_t    subexp;
                BITMAPB_t BitMap;
                BITMAPB_t BitMask;

                Digit = JU_DIGITATSTATE(Index, cJU_ROOTSTATE);

JudyMGBranchB:
                Pjbb   = P_JBB(Pjp->jp_Addr);
                subexp = Digit / cJU_BITSPERSUBEXPB;

                BitMap = JU_JBB_BITMAP(Pjbb, subexp);
                BitMask = JU_BITPOSMASKB(Digit);

                if (! (BitMap & BitMask)) JL_MG_MISS(Lane, PPValue);

                Lane->mg_Pjp = P_JP(JU_JBB_PJP(Pjbb, subexp))
                             + j__udyCountBitsB(BitMap & (BitMask - 1));
                JL_MG_PREFETCH(Lane->mg_Pjp);
                return;                         // next epoch: dispatch child.
            }

// JPBRANCH_U*: the child JP address is arithmetic on the fetched JP --
// compute it, prefetch it, and spend exactly one epoch per level.  (No
// serial-style fallthrough chaining here: waiting on the child IS the
// epoch boundary.)

            case cJU_JPBRANCH_U:
                Lane->mg_Pjp = JU_JBU_PJP(Pjp, Index, cJU_ROOTSTATE);
                JL_MG_PREFETCH(Lane->mg_Pjp);
                return;

            case cJU_JPBRANCH_U7:
                // JU_DCDNOTMATCHINDEX() would be a no-op.
                Lane->mg_Pjp = JU_JBU_PJP(Pjp, Index, 7);
                JL_MG_PREFETCH(Lane->mg_Pjp);
                return;

            case cJU_JPBRANCH_U6:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 6)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Pjp = JU_JBU_PJP(Pjp, Index, 6);
                JL_MG_PREFETCH(Lane->mg_Pjp);
                return;

            case cJU_JPBRANCH_U5:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 5)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Pjp = JU_JBU_PJP(Pjp, Index, 5);
                JL_MG_PREFETCH(Lane->mg_Pjp);
                return;

            case cJU_JPBRANCH_U4:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 4)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Pjp = JU_JBU_PJP(Pjp, Index, 4);
                JL_MG_PREFETCH(Lane->mg_Pjp);
                return;

            case cJU_JPBRANCH_U3:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 3)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Pjp = JU_JBU_PJP(Pjp, Index, 3);
                JL_MG_PREFETCH(Lane->mg_Pjp);
                return;

            case cJU_JPBRANCH_U2:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 2)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Pjp = JU_JBU_PJP(Pjp, Index, 2);
                JL_MG_PREFETCH(Lane->mg_Pjp);
                return;

// JPLEAF*: two-phase -- prefetch the packed key area now, run the search
// (the same j__udySearchLeaf*() JudyLGet inlines) next epoch:

            case cJU_JPLEAF1:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 1)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Level = 1;
                goto JudyMGLeaf;

            case cJU_JPLEAF2:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 2)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Level = 2;
                goto JudyMGLeaf;

            case cJU_JPLEAF3:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 3)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Level = 3;
                goto JudyMGLeaf;

            case cJU_JPLEAF4:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 4)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Level = 4;
                goto JudyMGLeaf;

            case cJU_JPLEAF5:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 5)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Level = 5;
                goto JudyMGLeaf;

            case cJU_JPLEAF6:
                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 6)) JL_MG_MISS(Lane, PPValue);
                Lane->mg_Level = 6;
                goto JudyMGLeaf;

            case cJU_JPLEAF7:
            {
                // JU_DCDNOTMATCHINDEX() would be a no-op.
                Lane->mg_Level = 7;

JudyMGLeaf:
                Lane->mg_Pop1    = JU_JPLEAF_POP0(Pjp) + 1;
                Lane->mg_u.mg_Pjll = P_JLL(Pjp->jp_Addr);
                jl_mg_prefetch_range(Lane->mg_u.mg_Pjll,
                                     Lane->mg_Pop1 * Lane->mg_Level);
                Lane->mg_State = JL_MG_LEAF;
                return;
            }

// JPLEAF_B1: two-phase -- prefetch the subexpanse bitmap and value-
// pointer words, resolve next epoch:

            case cJU_JPLEAF_B1:
            {
                Pjlb_t Pjlb;

                if (JU_DCDNOTMATCHINDEX(Index, Pjp, 1)) JL_MG_MISS(Lane, PPValue);

                Pjlb  = P_JLB(Pjp->jp_Addr);
                Digit = JU_DIGITATSTATE(Index, 1);

                Lane->mg_u.mg_Pjlb = Pjlb;
                Lane->mg_Digit     = Digit;
                Lane->mg_Subexp    = (uint8_t)(Digit / cJU_BITSPERSUBEXPL);
                JL_MG_PREFETCH(&JU_JLB_BITMAP(Pjlb, Lane->mg_Subexp));
                JL_MG_PREFETCH(&JL_JLB_PVALUE(Pjlb, Lane->mg_Subexp));
                Lane->mg_State = JL_MG_LEAFB1;
                return;
            }

// JPIMMED*: decoded from the JP itself; terminal, no dependent fetch.
// (Note the contents of jp_DcdPopO are different for cJU_JPIMMED_*_01.)

            case cJU_JPIMMED_1_01:
            case cJU_JPIMMED_2_01:
            case cJU_JPIMMED_3_01:
            case cJU_JPIMMED_4_01:
            case cJU_JPIMMED_5_01:
            case cJU_JPIMMED_6_01:
            case cJU_JPIMMED_7_01:

                if (JU_JPDCDPOP0(Pjp) != JU_TRIMTODCDSIZE(Index))
                    JL_MG_MISS(Lane, PPValue);

                JL_MG_HIT(Lane, PPValue, &(Pjp->jp_Addr)); // immediate value area.

            case cJU_JPIMMED_1_02:
            case cJU_JPIMMED_1_03:
            case cJU_JPIMMED_1_04:
            case cJU_JPIMMED_1_05:
            case cJU_JPIMMED_1_06:
            case cJU_JPIMMED_1_07:
            {
                int      pop1 = JU_JPTYPE(Pjp) - cJU_JPIMMED_1_02 + 2;
                uint8_t *Pleaf = Pjp->jp_LIndex;
                int      posidx;

                for (posidx = 0; posidx < pop1; ++posidx)
                    if (Pleaf[posidx] == (uint8_t)Index)
                        JL_MG_HIT(Lane, PPValue, P_JV(Pjp->jp_Addr) + posidx);

                JL_MG_MISS(Lane, PPValue);
            }

            case cJU_JPIMMED_2_02:
            case cJU_JPIMMED_2_03:
            {
                int       pop1 = JU_JPTYPE(Pjp) - cJU_JPIMMED_2_02 + 2;
                uint16_t *Pleaf = (uint16_t *)(Pjp->jp_LIndex);
                int       posidx;

                for (posidx = 0; posidx < pop1; ++posidx)
                    if (Pleaf[posidx] == (uint16_t)Index)
                        JL_MG_HIT(Lane, PPValue, P_JV(Pjp->jp_Addr) + posidx);

                JL_MG_MISS(Lane, PPValue);
            }

            case cJU_JPIMMED_3_02:
            {
                Word_t   i_ndex;
                uint8_t *a_ddr = Pjp->jp_LIndex;
                Word_t   key   = JU_LEASTBYTES(Index, 3);

                JU_COPY3_PINDEX_TO_LONG(i_ndex, a_ddr);
                if (i_ndex == key)
                    JL_MG_HIT(Lane, PPValue, P_JV(Pjp->jp_Addr) + 0);

                JU_COPY3_PINDEX_TO_LONG(i_ndex, a_ddr + 3);
                if (i_ndex == key)
                    JL_MG_HIT(Lane, PPValue, P_JV(Pjp->jp_Addr) + 1);

                JL_MG_MISS(Lane, PPValue);
            }

            } // switch (JU_JPTYPE())
        }

// Linear-leaf key area has arrived; the search is the lane's work phase
// and is the library's own inline search code:

        case JL_MG_LEAF:
        {
            Pjll_t Pjll = Lane->mg_u.mg_Pjll;
            Word_t Pop1 = Lane->mg_Pop1;
            int    posidx;

            switch (Lane->mg_Level)
            {
            case 1:
                if ((posidx = j__udySearchLeaf1(Pjll, Pop1, Index)) < 0)
                    JL_MG_MISS(Lane, PPValue);
                JL_MG_HIT(Lane, PPValue, JL_LEAF1VALUEAREA(Pjll, Pop1) + posidx);
            case 2:
                if ((posidx = j__udySearchLeaf2(Pjll, Pop1, Index)) < 0)
                    JL_MG_MISS(Lane, PPValue);
                JL_MG_HIT(Lane, PPValue, JL_LEAF2VALUEAREA(Pjll, Pop1) + posidx);
            case 3:
                if ((posidx = j__udySearchLeaf3(Pjll, Pop1, Index)) < 0)
                    JL_MG_MISS(Lane, PPValue);
                JL_MG_HIT(Lane, PPValue, JL_LEAF3VALUEAREA(Pjll, Pop1) + posidx);
            case 4:
                if ((posidx = j__udySearchLeaf4(Pjll, Pop1, Index)) < 0)
                    JL_MG_MISS(Lane, PPValue);
                JL_MG_HIT(Lane, PPValue, JL_LEAF4VALUEAREA(Pjll, Pop1) + posidx);
            case 5:
                if ((posidx = j__udySearchLeaf5(Pjll, Pop1, Index)) < 0)
                    JL_MG_MISS(Lane, PPValue);
                JL_MG_HIT(Lane, PPValue, JL_LEAF5VALUEAREA(Pjll, Pop1) + posidx);
            case 6:
                if ((posidx = j__udySearchLeaf6(Pjll, Pop1, Index)) < 0)
                    JL_MG_MISS(Lane, PPValue);
                JL_MG_HIT(Lane, PPValue, JL_LEAF6VALUEAREA(Pjll, Pop1) + posidx);
            default:
                if ((posidx = j__udySearchLeaf7(Pjll, Pop1, Index)) < 0)
                    JL_MG_MISS(Lane, PPValue);
                JL_MG_HIT(Lane, PPValue, JL_LEAF7VALUEAREA(Pjll, Pop1) + posidx);
            }
        }

// Bitmap-leaf subexpanse has arrived:

        case JL_MG_LEAFB1:
        {
            BITMAPL_t BitMap  = JU_JLB_BITMAP(Lane->mg_u.mg_Pjlb, Lane->mg_Subexp);
            BITMAPL_t BitMask = JU_BITPOSMASKL(Lane->mg_Digit);
            Pjv_t     Pjv;

            if (! (BitMap & BitMask)) JL_MG_MISS(Lane, PPValue);

            Pjv = P_JV(JL_JLB_PVALUE(Lane->mg_u.mg_Pjlb, Lane->mg_Subexp));

            JL_MG_HIT(Lane, PPValue,
                      Pjv + j__udyCountBitsL(BitMap & (BitMask - 1)));
        }

        default:                // JL_MG_DONE: nothing to do (not reachable
            return;             // from the drain loop, which skips done lanes).
        }
}

// ****************************************************************************
// J U D Y   L   M U L T I   G E T
//
// Look up Count independent keys PIndex[0..Count-1] in PArray.  On return,
// PPValue[i] holds exactly what JudyLGet(PArray, PIndex[i], PJE0) would
// have returned -- a pointer to the key's value area, NULL for an absent
// key, or PPJERR for a corrupt array.  Returns the number of present keys
// (slots that are neither NULL nor PPJERR).
//
// Duplicate keys in PIndex are allowed (each slot is answered
// independently); Count == 0 is a no-op returning 0.  PIndex and PPValue
// must be non-NULL when Count > 0 (returns 0 without touching anything
// otherwise -- a contract violation, not an error code, mirroring the
// null-PPValue posture of the JLG() macro family).

FUNCTION Word_t JudyLMultiGet(
        Pcvoid_t      PArray,   // from which to retrieve.
        const Word_t *PIndex,   // keys to retrieve, Count of them.
        PPvoid_t     *PPValue,  // result slots, Count of them.
        Word_t        Count)    // number of keys.
{
        Word_t hits = 0;
        Word_t next;

        if (Count == 0 || PIndex == NULL || PPValue == NULL)
            return(0);

// Serial paths: an empty array misses everything; a root-level leaf
// (pop <= cJU_LEAFW_MAXPOP1) is a single cache-resident node; a small
// tree has nothing to overlap (measured x0.86-0.95 through the lanes);
// a tiny batch never fills the pipeline.  JudyLGet() handles all of
// these at full serial speed and is the behavioral reference anyway.

        if (PArray == (Pcvoid_t) NULL)
        {
            for (next = 0; next < Count; ++next)
                PPValue[next] = (PPvoid_t) NULL;
            return(0);
        }

        if ((JU_LEAFW_POP0(PArray) < cJU_LEAFW_MAXPOP1)   // root-level LEAFW.
         || ((P_JPM(PArray)->jpm_Pop0 + 1) < (Word_t) cJL_MULTIGET_SERIAL_POP1)
         || (Count < (Word_t) cJL_MULTIGET_SERIAL_COUNT))
        {
            for (next = 0; next < Count; ++next)
            {
                PPvoid_t Pv = JudyLGet(PArray, PIndex[next], PJE0);

                PPValue[next] = Pv;
                if (Pv != (PPvoid_t) NULL && Pv != PPJERR)
                    ++hits;
            }
            return(hits);
        }

// Pipelined path.  The array is known to start with a JPM here.

        {
            jl_mg_lane_t lanes[cJL_MULTIGET_LANES];
            Pjp_t        Pjptop = &(P_JPM(PArray)->jpm_JP);
            int          live   = 0;
            int          l;

#define JL_MG_LANE_START(Lane,Slot)                                     \
            {                                                           \
                (Lane)->mg_Index    = PIndex[(Slot)];                   \
                (Lane)->mg_Slot     = (Slot);                           \
                (Lane)->mg_Pjp      = Pjptop;                           \
                (Lane)->mg_State    = JL_MG_JP;                         \
                (Lane)->mg_Fallback = 0;                                \
                JL_MG_PREFETCH(Pjptop);                                 \
            }

            for (l = 0; l < cJL_MULTIGET_LANES; ++l)  // lanes beyond Count
                lanes[l].mg_State = JL_MG_DONE;       // must read as done.

            for (next = 0; live < cJL_MULTIGET_LANES && next < Count; ++next)
            {
                JL_MG_LANE_START(&lanes[live], next);
                ++live;
            }

            while (live > 0)
            {
                for (l = 0; l < cJL_MULTIGET_LANES; ++l)
                {
                    jl_mg_lane_t *Lane = &lanes[l];

                    if (Lane->mg_State == JL_MG_DONE)
                        continue;

                    jl_mg_step(Lane, PPValue);

                    if (Lane->mg_State != JL_MG_DONE)
                        continue;

                    // Lane finished: resolve fallbacks (unrecognized JP
                    // type -- never taken on a healthy tree), count, and
                    // refill the lane with the next pending key.

                    if (Lane->mg_Fallback)
                        PPValue[Lane->mg_Slot] =
                            JudyLGet(PArray, Lane->mg_Index, PJE0);

                    if (PPValue[Lane->mg_Slot] != (PPvoid_t) NULL
                     && PPValue[Lane->mg_Slot] != PPJERR)
                        ++hits;

                    if (next < Count)
                    {
                        JL_MG_LANE_START(Lane, next);
                        ++next;
                    }
                    else
                    {
                        --live;
                    }
                }
            }

#undef JL_MG_LANE_START
        }

        return(hits);

} // JudyLMultiGet()
