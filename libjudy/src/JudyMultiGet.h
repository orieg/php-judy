// php-judy addition -- NOT an upstream Judy-1.0.5 file (patch O5, see
// libjudy/PATCHES.md, issue #142).  Distributed under the same license as
// the rest of this subtree:
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
// Public declaration of JudyLMultiGet(), the batched (AMAC software-
// pipelined) counterpart of JudyLGet() provided by the bundled libJudy
// only -- a system libJudy does not have this entry point, so callers
// must guard usage (php-judy guards on HAVE_JUDY_BUNDLED).
//
// See JudyCommon/JudyMultiGet.c for semantics; the short version:
// PPValue[i] receives exactly what JudyLGet(PArray, PIndex[i], PJE0)
// would return (value-area pointer, NULL, or PPJERR), and the return
// value is the number of present keys.  Answers are computed against the
// array state at call time; like JudyLGet()'s, the returned pointers are
// invalidated by any subsequent insert/delete into the same array.

#ifndef _JUDYMULTIGET_INCLUDED
#define _JUDYMULTIGET_INCLUDED

#include "Judy.h"

#ifdef __cplusplus
extern "C" {
#endif

extern Word_t JudyLMultiGet(Pcvoid_t PArray, const Word_t *PIndex,
                            PPvoid_t *PPValue, Word_t Count);

#ifdef __cplusplus
}
#endif

// Convenience macro in the JLG() style:

#define JLMG(Hits, PArray, PIndex, PPValue, Count)                       \
        ((Hits) = JudyLMultiGet((Pcvoid_t)(PArray), (PIndex), (PPValue), \
                                (Count)))

#endif // _JUDYMULTIGET_INCLUDED
