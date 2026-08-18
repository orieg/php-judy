# Patches applied to the bundled libJudy

The sources under `libjudy/` were imported pristine from upstream
Judy-1.0.5 (see [README.md](README.md) for provenance). Every
modification since that import follows one discipline:

- **one patch = one commit**, diffable against the pristine import
  commit (`git log --follow libjudy/` finds it; the import commit
  touches only `libjudy/` and says "pristine" in its subject);
- **one entry in the table below** per patch, stating what changed,
  which files, why, when, and the tracking issue;
- **a per-file change notice** at the top of every modified file
  (a short comment: date + summary of the change), satisfying
  LGPL-2.1 §2(b)'s requirement that modified files carry prominent
  notices of the change and its date.

No reformatting, no drive-by cleanups: a diff against the pristine
import must show only deliberate, documented changes.

The pre-generated table files (`src/Judy1/Judy1Tables.c`,
`src/JudyL/JudyLTables.c`) and the build shims under `src/wrappers/`
are php-judy **additions**, not modifications of upstream files —
upstream generates its equivalents at build time — so they carry their
own provenance headers instead of §2(b) change notices and do not
appear in the ledger below.

## Patches

| Patch | Category | Files | Why | Date | Issue |
| ----- | -------- | ----- | --- | ---- | ----- |
| P1 | Correctness | `src/JudyCommon/JudyPrivateBranch.h` (plus new pins in the php-judy-addition `src/wrappers/judy_static_asserts.c`) | Judy1 immediate JPs hold up to `cJ1_IMMED1_MAXPOP1` = 15 one-byte indexes written/read contiguously through `jp_1Index` (`JudyCascade.c:604`, `JudyGet.c` IMMED cases), but `j_pi_1Index` was declared with only `sizeof(Word_t)` = 8 bytes — every access past index 7 was undefined behavior, exploited by gcc 15 at `-O3` and gcc 13/14 at `-O2 -funroll-loops` into silent Judy1/BITSET key loss. Widened to `(2 * sizeof(Word_t)) - 1` bytes via a union keeping `j_pi_LIndex` at its old offset (`sizeof(Word_t)`) and `sizeof(jp_t)` unchanged at 16; accessor macros redirected. The immediate-capacity `_Static_assert` pins deferred from Stage 1 (they fail on the pristine layout) now live in `judy_static_asserts.c` and must pass forever after. | 2026-08-18 | [#131](https://github.com/orieg/php-judy/issues/131), [#127](https://github.com/orieg/php-judy/issues/127), [#142](https://github.com/orieg/php-judy/issues/142) |
| P2 | Correctness | `src/JudyCommon/JudyPrivate.h` | The leaf-search method guard read `(!defined(SEARCH_BINARY)) \|\| (!defined(SEARCH_LINEAR))`, so building with `-DSEARCH_LINEAR` still defined `SEARCH_BINARY` and the binary macros (defined later) won — the linear method was unselectable. AND the linear `SEARCHLEAFNONNAT` ignored its `COPYINDEX` parameter, hardcoding `JU_COPY3_PINDEX_TO_LONG`: had the guard been fixed alone, every LEAF5/6/7 search would read only 3 bytes per index — silent data loss (#127: urand16 went 20000→0 hits). Guard flipped to `&&` and `COPYINDEX` honored, landed together deliberately so no intermediate state turns an inert flag into a data-losing one. Default (`SEARCH_BINARY`) builds are textually unaffected. | 2026-08-18 | [#127](https://github.com/orieg/php-judy/issues/127), [#142](https://github.com/orieg/php-judy/issues/142) |
| P5 | Portability/Correctness | `src/Judy.h`, `src/JudyCommon/JudyInsArray.c`, `src/JudyCommon/JudyMallocIF.c`, `src/JudyCommon/JudyPrevNextEmpty.c`, `src/JudyCommon/JudyPrivate.h`, `src/JudyCommon/JudyPrivateBranch.h`, `src/JudySL/JudySL.c` | LLP64 (Windows/MSVC x64): `unsigned long` is 4 bytes there, so `Word_t` must be `unsigned __int64` under `_WIN64`, and every all-ones / shifted / masked `UL`/`L` constant used in a `Word_t` context truncated (`~0UL`, `(-1UL)`, `0x100UL`, `0xffL`) or vanished (`1UL << N` and sign-extending `1L << N` for N ≥ 32 — the `JudyPrevNextEmpty` infinite loop). These are the six mechanical fixes CI/release previously applied as download-time regexes, absorbed as real source diffs so the tree builds on Windows unpatched. | 2026-08-18 | [#127](https://github.com/orieg/php-judy/issues/127), [#142](https://github.com/orieg/php-judy/issues/142) |
