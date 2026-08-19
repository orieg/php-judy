# libJudy modernization and vendoring: the investigation and execution record (issue #113)

Canonical record for the question *"we are keeping Judy — do we vendor it, and
what do we patch?"* — and, since the decision fired, for what executing it
found. §1–§10 are the investigation, preserved as written; nothing in them is
reopened. [§11](#11-execution-record-stages-03) is the execution record.

**Status: the superseded-when condition has fired.** The vendoring decision was
executed — Stage 1 made the bundled `libjudy/` tree the default build
([PR #146](https://github.com/orieg/php-judy/pull/146)) — and implementation is
tracked by [#142](https://github.com/orieg/php-judy/issues/142). This document
is no longer a live decision record; it is the completed history the
implementation traces back to, and new execution results are appended to §11
until #142 closes.

**Why this is a separate document.**
[BACKEND_EVALUATION.md](../../BACKEND_EVALUATION.md) answers *"which backend?"*
and concluded **keep Judy**. That verdict is not reopened here. This document
answers the different question that follows from it — given that we keep Judy,
is the incumbent itself carrying unrealised headroom, and does realising it
require vendoring the library? The evidence for that question is far larger than
BACKEND_EVALUATION.md's tight single-question structure can absorb without
damaging it, so it lives here and is linked from there.

**Verdict — executed: vendor stock 1.0.5 plus patches, gated. The strongest
argument is correctness, not performance.** The decision record is
[§10](#10-verdict-and-the-gates-that-remained-open-at-decision-time); the
execution record is
[§11](#11-execution-record-stages-03).

---

## 1. Scope and status

| | |
| --- | --- |
| **Question** | Does libJudy 1.0.5 have exploitable headroom, and what does realising it cost? |
| **Status** | Investigation closed; decision executed. First verdict retracted; round 2 measured; external review round-trip closed; vendoring executed via [#142](https://github.com/orieg/php-judy/issues/142) (Stages 0–2 plus optimizations O1, O3 and O4a/b/d merged; O2, O4c and O5 dropped at their gates, O5 since reopened behind a partition gate, measurement in progress in §11.11 — see [§11](#11-execution-record-stages-03); Stage 3 re-reviewed 2026-08-18, §11.10). |
| **Primary issue** | [#113](https://github.com/orieg/php-judy/issues/113) (investigation, closed); [#142](https://github.com/orieg/php-judy/issues/142) (implementation tracker) |
| **Correctness issues raised** | [#131](https://github.com/orieg/php-judy/issues/131), [#127](https://github.com/orieg/php-judy/issues/127) — both fixed and closed by [PR #147](https://github.com/orieg/php-judy/pull/147) |
| **Harness defects raised** | [#122](https://github.com/orieg/php-judy/issues/122), fixed by [PR #124](https://github.com/orieg/php-judy/pull/124) |
| **Shipped from it** | [PR #134](https://github.com/orieg/php-judy/pull/134) (the miscompile detector), then the whole #142 series: PRs [#143](https://github.com/orieg/php-judy/pull/143), [#144](https://github.com/orieg/php-judy/pull/144), [#145](https://github.com/orieg/php-judy/pull/145), [#146](https://github.com/orieg/php-judy/pull/146), [#147](https://github.com/orieg/php-judy/pull/147), [#148](https://github.com/orieg/php-judy/pull/148), [#149](https://github.com/orieg/php-judy/pull/149), [#150](https://github.com/orieg/php-judy/pull/150) |
| **Superseded when** | **fired** — the decision was executed (Stage 1, [PR #146](https://github.com/orieg/php-judy/pull/146)); this record is history plus the ongoing [§11](#11-execution-record-stages-03) execution log |

Related but out of scope: [#111](https://github.com/orieg/php-judy/issues/111)
asks whether a *different* structure (Roaring / burst-trie / MPHF) closes gaps
Judy cannot. Licensing is orthogonal and is not solved by anything here — a fork
of LGPL-2.1 stays LGPL-2.1, and so does a vendored copy.

## 2. What was asked, and why

The framing that started it: **does the incumbent have unrealised headroom?**
Judy 1.0.5 is 2007 code. It predates SSE4.2 POPCNT (2008), widespread AVX2
(2013), and ARM64 entirely. A disassembly of the arm64 `libJudy.1.dylib` on the
maintainer's machine (Homebrew 1.0.5, 45,086 instructions) found **zero**
hardware popcount instructions, **zero** NEON/SIMD vector registers and **zero**
prefetch hints, while Judy's bitmap nodes compute subexpanse offsets through
precomputed lookup tables.

The strategic attraction was specific rather than general. Memory at scale is
the only axis this project has confirmed a win on. Replacing the structure risks
that win by construction — which is exactly how ART failed, at −27% memory.
Optimising the incumbent preserves node compression by construction while
attacking the axes we lose. And the one measured loss with a plausible libJudy
cause was the write-heavy merge clause.

That last motivation did not survive contact with the evidence
([§5.5](#55-the-merge-target-was-mis-specified-and-pr-123-measured-exactly-zero)).
The question that survived is the correctness one, which nobody was looking for.

## 3. Method, and what the method could not do

**Host.** A dedicated, idle 24-core i9-12900F (Alder Lake — POPCNT and AVX2
present), gcc 11.4.0, runs pinned to a P-core with `taskset`. Load average was
checked before and between every configuration and was fully accounted for by
the single pinned benchmark. The standing repo rule applies and was applied: a
load average above cores/2, or any non-target process above ~50% CPU, makes a
run uninterpretable, and such runs are discarded rather than caveated. Source
review and codegen work used a separate arm64 Darwin host with gcc-15 (Homebrew
15.2.0) and Apple clang 21; those results are compiler output or sanitizer
verdicts, not timings, and are unaffected by load.

**Replication unit.** Round 2 uses **5 independent builds per arm with
randomized link order**, a per-build median of 5 interleaved trials, and a
percentile bootstrap over builds. This is the correction for round 1's design
(see [§4](#4-the-first-verdict-and-its-retraction)): every treatment changes code
layout as a side effect, so process repetitions of a single binary are
pseudo-replicates and cannot separate a treatment from its layout.

**Flag-only control arms.** The popcount A/B carries a third arm built with
`-O2 -mpopcnt` and no source change. It is null in all 18 corpus × metric cells,
which calibrates this design's false-positive rate at this effect size to zero.
Round 1 had no such calibration.

**Corpora.** Thirteen, after [#122](https://github.com/orieg/php-judy/issues/122)
was fixed — uniform-random, variable-length, clustered and dense integer shapes,
with a node-type census per corpus so the node-type regime is visible rather
than assumed.

**No PMU was ever available.** `perf_event_paranoid=4` and no sudo, for the whole
investigation. **Every cache figure and every miss count below is cachegrind
simulation, and every derived stall time is a bound rather than a measurement.**
Miss counts from the simulator are trustworthy; time attributed to them is not,
and the retraction in §4 is largely a record of what happens when that
distinction is dropped. Callgrind runs from round 2 onward are phase-gated
(`--collect-atstart=no --toggle-collect=lookup_phase`) so build and teardown are
excluded; round 1's attribution mixed all three phases.

**Single host for timing, single compiler family, no independent review.** Two
figures quoted below (the MLP 3.2× dependency-structure ratio, and the stride
128 B / 17 B comparison) were taken by a reviewer on an M1 under moderate load;
they are ratios and are not replications of this host's absolutes.

## 4. The first verdict, and its retraction

**A first round concluded that libJudy has no exploitable headroom from modern
CPU features or compiler work, and that the popcount/SIMD work item should be
closed. That verdict was retracted.** It is recorded here in full because the
reasons it failed are the reasons the round-2 design looks the way it does.

What survives from round 1, and is well earned:

> `-O2` is the correct build flag for libJudy. `-O3`, LTO and PGO are
> neutral-to-negative on these workloads on this host, and autovectorisation
> does not touch `JudyLGet` / `JudySLGet` / `JudyHSGet`. Further compiler-**flag**
> investment is closed.

What was retracted, and why:

**The popcount item was closed on an experiment that could not detect it.** Only
*spontaneous emission by GCC* was tested. GCC's popcount idiom recognition fires
on loop forms; Judy's `j__udyCountBits{B,L}` is straight-line SWAR using the
masked variant `(w & M) + ((w & ~M) >> k)`. Zero POPCNT emissions was the
*predicted* outcome — confirmed independently on GCC 15.2 and clang 21, both of
which also emit zero for Judy's variant while matching the canonical form
instantly. The change itself was never written. A null from an experiment that
could not have produced a positive is not evidence.

**The corpus was degenerate.** Six of 16 byte positions varied, 10 values each,
and 10^6 keys exactly saturated a 10^6 key space — a perfectly uniform,
perfectly full tree. With `cJU_BRANCHLMAXJPS` = 7 and 10 populated subexpanses,
**every branch in the tree was a `BRANCH_B` bitmap at identical density**.
`BRANCH_L`, `BRANCH_U` and every density transition between them were never
observed, and `LEAF_B1` was never reached at all. The popcount-sensitive path
was sampled at exactly one point of a distribution and the result generalised.
Filed as [#122](https://github.com/orieg/php-judy/issues/122).

**The "~80% memory-stall" figure rested on a self-refuting model.** It assumes
memory-level parallelism of 1. At MLP = 1, 56.0 M DRAM misses × 70–90 ns is
3.92–5.04 s of serialized DRAM inside a **3.76 s** run — 104–134% of every cycle
available, leaving none to retire 24.82 G instructions. MLP was then measured at
3.2× (same loop, only the dependency structure changed: independent lookups
16.52 ns, next-key-derived-from-fetched-value 52.98 ns). Bandwidth utilisation
was ~1.2% of DDR5-4800. Latency-bound at 1% of bandwidth is the signature of
*available* headroom through miss concurrency, not of none.

**n = 1 build per config.** The 7 process repetitions were pseudo-replicates of
one binary. Under known code-layout noise envelopes every result under ~5% was
uninterpretable — including the 2.0% `-O3` insert regression and the 3.0% PGO
gain that the round's conclusions leaned on. Only the 12.6% `JLN` iterate result
survived.

One reviewer criticism was itself refuted by measurement, and is recorded as
such: the claim that the 122 MiB harness key buffer explained much of the
iteration-vs-random gap does not hold — stride 128 B gives 196.31 ns, stride
17 B gives 188.88 ns, a 3.8% difference rather than ~50%, and the random-order
figure replicates within 1% across M1 and i9-12900F. The magnitude of the stall
claim falls via MLP; the underlying natural experiment is sound.

Two things also went wrong in the *reporting* rather than the measurement: the
summary over-generalised what the POC had measured, and an earlier
recommendation in the same thread to apply the one-line `SEARCH_LINEAR` guard
fix was actively dangerous (see [§6.2](#62-127--search_linear-is-a-no-op-masking-a-data-loss-defect-and-the-obvious-fix-makes-it-worse)).

## 5. Findings — measured

All figures `(measured)` on the host in §3 unless tagged `(projected)`. Ratios
are treatment/base, so **below 1.0 is faster**.

### 5.1 The popcount item splits in two

Round 2 instrumented libJudy to count `jp_Type` dispatches and took a static
node census, giving a popcount duty cycle per lookup across 13 corpora. That
alone reorganised the question:

| corpus | popcount calls/lookup | which routine |
| --- | ---: | --- |
| `urand16`, `urand8`, `varlen`, `wbase4`, `wbase6` | **0.000** | — |
| `struct16` (the round-1 corpus) | 1.000 | `CountBitsB` (32-bit) |
| `wclust` | 0.554 | `CountBitsB` |
| `wbase16` | 1.251 | both |
| `wdense`, `wbase64`, `wbase128` | 1.000 | `CountBitsL` (64-bit) |

**High-entropy string keys produce zero popcount calls.** With random 16-byte
keys the first 8 bytes are already unique, so JudySL resolves in one JudyL level
plus a `strcmp` of the stored remainder. `BRANCH_L` is never produced by any
realistic corpus — reaching it required constructing key alphabets of <=7
children. The round-1 corpus over-represented the half of popcount that turns
out not to matter and never touched the half that does.

Phase-gated callgrind then put popcount at **0.00%** of gated instructions on
`urand16`/`varlen`/`wbase4`, 4.03% on `struct16`, 5.25% on `wclust`, **12.15%**
on `wbase16` and **13.21%** on `wdense`. 12–13% cleared the gate, so the A/B ran.

| corpus | base ns | `-mpopcnt` (flag-only control) | popcount patch |
| --- | ---: | ---: | --- |
| `wbase4` | 43.96 | ×0.9964 | ×1.0055 |
| `urand16` | 185.30 | ×1.0014 | ×0.9975 |
| `struct16` | 204.02 | ×1.0002 | ×0.9951 [0.9921, 1.0005] |
| `wclust` | 49.89 | ×0.9958 | ×0.9759 |
| `wbase16` | 45.27 | ×0.9987 | **×0.8414 [0.8362, 0.8465]** |
| `wdense` | 27.20 | ×0.9993 | **×0.8290 [0.8263, 0.8381]** |

- **`j__udyCountBitsB` (bitmap BRANCH): CLOSED ON MEASUREMENT.** Genuinely
  exercised (1.00 calls/lookup on `struct16`, 0.554 on `wclust`), 4–5% of
  instructions, buys **0–2.4% of time**, and the CI includes 1.0 on `struct16`.
  Its ~15-cycle chain hides behind the 3.15 LL misses/lookup the traversal
  already causes. This is the closure round 1 claimed but could not support.
- **`j__udyCountBitsL` (bitmap LEAF): NOT closed.** **17% cache-resident**
  (`wdense` ×0.8290, `wbase16` ×0.8414), **6.2% out of cache** (`wdense` at
  n = 4×10^7, ~330 MB, ×0.9384 [0.9309, 0.9448]). Per-build ranges are disjoint
  at both sizes — `wdense` base [27.05, 27.29] vs patched [22.52, 22.72] —
  against `wbase4` base [43.50, 44.46] vs patched [43.39, 44.40], which fully
  overlap. Mechanism by counts rather than timing: the patch removes 12.5% of
  instructions on `wdense` while leaving branch behaviour bit-identical
  (Bc 2,600,002 / Bcm 6 in both arms) and memory traffic unchanged to 0.03%.
  `BITMAP_LEAF32x8` is an independent cross-check in the same direction — it
  drops `CountBitsL` from 6 SWAR rounds to 5 and buys 2.5% where the full patch
  buys 15.9%.

**Scope for php-judy, established from source.** `CountBitsL` on the lookup path
is `#ifdef JUDYL` only; Judy1's `LEAF_B1` arm is `JU_BITMAPTESTL`, a plain bit
test. So **`Judy::BITSET` sees nothing, the `Judy::STRING_*` types see nothing**
(zero calls/lookup on random and variable-length keys), and the win lands on
**`Judy::INT_TO_*` with dense-ish integer keys** and nowhere else.

Recorded as unexplained: the out-of-cache *absolute* saving grew (12.4 ns vs
4.65 ns) while the *ratio* shrank, which the chain-length model does not predict.
Duty cycle is identical at both sizes, so the ratio shrink is Amdahl.

### 5.2 Memory-level parallelism is the largest effect found

An N-lane software-pipelined descend (AMAC shape) covering
`BRANCH_U*`/`BRANCH_B*`/`LEAF_B1`, gated on exact agreement with `JudyLGet`
before any timing was printed. Deconfounded — both arms linked against the
popcount build, so this is MLP alone:

| corpus | serial | lanes = 8 | lanes = 16 | speedup | fallbacks |
| --- | ---: | ---: | ---: | ---: | ---: |
| `wdense` | 20.64 ns | 12.74 | 12.76 | **×1.62** | 0 |
| `wbase64` | 26.14 ns | 14.72 | 14.56 | **×1.79** | 0 |

Hit counts identical, zero fallbacks. Against the stock `-O2` serial baseline the
combined popcount + MLP effect is **~×2.0**. (`wsparse` showed ×1.23 but with
fallbacks on every lookup, because the prototype does not cover LEAF6 — that
number is not usable.)

**This needs a batched entry point libJudy does not expose**, so it helps only
bulk operations: `mergeWith()`, `union()`, `keys($lo, $hi)`, `toArray()`.
(Executed and DROPPED — none of those candidates survived contact with the
current code, and the win does not survive heterogeneous key batches; the
full record is §11.9.)

### 5.3 Compiler flags are null, and `-O2` is load-bearing

`-march=native` (resolving to `-march=alderlake -mpopcnt -mavx2`) moves nothing:
point lookup is indistinguishable across every config. `-O3` costs 12.6% on `JLN`
ordered iteration and 2.0% on insert; LTO 2.5% on insert. Where `-O3` did
vectorise it landed on `j__udyInsArray` (bulk insert, not php-judy's per-key
path) and the rare Cascade routines — `JudyLGet`, `JudySLGet` and `JudyHSGet`
received zero vector instructions.

The retraction's caveat on this point — that the shared build exported 102
internal `j__udy*` symbols with no `-fvisibility=hidden`, so LTO/PGO could not
inline through them — was **discharged on measurement, and the hypothesis was
wrong**. With the treatment landed (152 exported symbols → 50, all 102 internal
symbols local via `-fno-semantic-interposition` + `-export-symbols-regex`; the
LTO arms needed `-Wl,--version-script` because libtool's `nm`-based extraction
breaks under `-flto`), instruction counts came back **byte-identical to base** on
all three corpora and all six `get` CIs straddle 1.0. It does not bind because
the hot-path calls are to the **public** API (`JudySLGet` → `JudyLGet`), which
must stay exported; the 102 internal symbols were `static inline` in headers and
already inlined at `-O2`. LTO is not rescued either — it emits *more* copies of
the bit-count helpers, not fewer (46/134 against base's 36/108).

**`-O2` is correct on performance grounds and load-bearing on correctness
grounds** — see [§6.1](#61-131--gcc--o3-silently-loses-judybitset-keys-live).

`BITMAP_BRANCH16x16` is mixed and not a clear ship (`get` −0.5…−0.9%, `iterate`
−3…−5%, but a **+1.2% `get` regression** on `wclust`). PGO was built but not run
under the round-2 design; round 1's PGO figures sit inside the n = 1 noise
envelope and are not usable.

### 5.4 The allocator hypothesis is much weaker than it looked

Round 1's DRAM attribution put ~24% of LL read misses in the glibc allocator
serving Judy's per-node malloc/free (`malloc_consolidate` 11.2%, `unlink_chunk`
5.3%, `free` 4.8%, `_int_free` 2.3%) and proposed an arena. Two results bound it:

- **`GLIBC_TUNABLES` cannot test the hypothesis, and that suggestion is
  retracted.** `ld.so --list-tunables` accepts `glibc.malloc.tcache_max = 0x1040`,
  but that is only the tunable register — `do_set_tcache_max()` silently ignores
  any value above `MAX_TCACHE_SIZE` (1032 B). A malloc/free ping-pong shows the
  cliff staying at 1032 → 1040 B in all three configurations (5.28 → 12.89 ns
  stock; 5.38 → 12.49 with `tcache_max=4160`; 5.52 → 13.07 with `=1032`). The
  A/B came back null at <=0.6% and **that null is uninterpretable.**
- **The mechanism touches under 0.3% of allocations.** A perfect arena saves
  ~7.5 ns per alloc/free pair above 1032 B, and the static node census puts nodes
  that large (the `BRANCH_U` family) at **0.22–0.29% of all nodes**.

The arena stays on the list but is not promoted. Its round-1 DRAM attribution
also mixed build, measure and teardown phases; an insert-phase-gated callgrind
run is the cheap gate that would give it a proper Amdahl bound, and it has not
been done. Note also that on macOS an allocator override cannot bind against a
system dylib at all ([§6.6](#66-the-macos-judymalloc-override-cannot-bind-and-it-is-not-a-source-defect)).

### 5.5 The merge target was mis-specified, and PR #123 measured exactly zero

The original motivation — a ~1.15× libJudy insert win flipping `mergeWith()`
from a loss to a win — was aimed at the wrong term.
[BENCHMARK.md](../../BENCHMARK.md) states the gap as `O(distinct lines) + overlap`
against `O(keys)`: **a complexity-class gap.** A constant-factor win on libJudy
shifts the crossover; it does not close it.

The crossover has since been located directly. Ratio is array/judy, so >1.0 means
Judy wins: parity lands at roughly **2.2M line/test pairs** (7000×500×14000,
1.005, CI [0.999, 1.017]), the CI lower bound clears 1.0 from about **2.5M**
(2,525,044 pairs, 1.012 [1.009, 1.015]), and Judy is **20% faster by 5.05M**
(1.201 [1.199, 1.203]). Both the closed popcount item and the proposed arena were
aimed beneath that.

The actual addressable finding was in php-judy, not libJudy:
`judy_object_merge_with_helper()` obtained a live value-slot pointer from
`JLF`/`JLN`, used it only as a loop condition, and then performed a full
re-descend for the key it was already standing on — three descends per element
where two suffice. Filed as
[#121](https://github.com/orieg/php-judy/issues/121), fixed in
[PR #123](https://github.com/orieg/php-judy/pull/123), and worth **10–24%** on
most types (`INT_TO_INT` 0.786, `INT_TO_PACKED` 0.779, `INT_TO_MIXED` 0.777,
`STRING_TO_INT` 0.840, `STRING_TO_MIXED` 0.818, `*_HASH`/`*_ADAPTIVE` 0.876 with
`optimizeIteration`).

**On the coverage-index workload it measured 1.0001, CI [0.9981, 1.0012] —
exactly zero, and it could not have been otherwise.** The coverage index is a
`Judy::BITSET`, and `BITSET` is the one type the change explicitly excludes,
because its merge branch reads no value at all. The whole search looked inside
libJudy while php-judy's own merge loop discarded a third of its work, and then
the fix landed on the single type the motivating workload does not use.

## 6. Findings — correctness

This is where the investigation actually paid, and none of it was what was being
looked for. Reachability from php-judy is stated explicitly for each defect
rather than assumed.

### 6.1 #131 — gcc `-O3` silently loses `Judy::BITSET` keys (LIVE)

**Reachable from php-judy, and the strongest argument in the whole issue.**

`JudyPrivateBranch.h:83` declares the Judy1 immediate index array as
`uint8_t j_pi_1Index[sizeof(Word_t)]` — 8 bytes. `Judy1.h:386` computes
`cJ1_IMMED1_MAXPOP1` as `(sizeof(jp_t) - 1) / 1`, which on 64-bit is **15** — the
value in upstream's own bracketed comment. Every Judy1 immediate type needs 15
bytes. `JudyCascade.c:604` then copies up to 15 bytes in.

GCC at `-O3` sees the 8-byte declared destination and **truncates the copy**,
saying so via `-Waggressive-loop-optimizations` at `Judy1Set.c:1506`. Indices
9–15 are never written while `jp_Type` still claims 15 entries.

| build | result (~30k `J1S`/`J1U`) |
| --- | --- |
| gcc-15 `-O0` / `-O1` / `-O2` | `J1C=26562 walked=26562 J1T_hits=26562` — clean |
| **gcc-15 `-O3`** | **`J1C=26563 walked=26558 J1T_hits=26558`** — 5 keys lost, `count()` over-reports |
| gcc-15 `-O3` + widened array | clean |
| Apple clang 21 `-O2` / `-O3` | clean — clang does not exploit it |

Isolation is exact: patching only `JudyCascade.c:604` to write through a
`(uint8_t *)PjpJP` cast restores correctness at `-O3`; patching only the
`Judy1Set.c:1506` loop does not. **The write site in Cascade is what bites, not
the read site in Get.**

**In php-judy terms**: `Judy::BITSET` accepts a key through `J1S` that
`offsetExists()` later denies, `foreach` skips, and `count()` disagrees with
iteration. Silent corruption — no crash, no exception, no error return —
reachable from ordinary clustered key distributions, on the type
`examples/coverage-index.php` is built on. Exposure is a packaging lottery:
Debian/Ubuntu/Fedora/RHEL ship Baskins' widening patch and are safe; Homebrew
ships stock sources but builds with clang and is safe by luck rather than design;
Alpine, conda, hand-built and any distro building with `gcc -O3` are each an
independent gamble.

**This is now detected rather than hoped about.**
[PR #134](https://github.com/orieg/php-judy/pull/134) ships
`tests/bitset_immed_cascade_integrity_001.phpt`, a deterministic
structure-derived pattern: 135 keys `(h << 8) | l` for `h` in 0..8 and `l` in
0..14 — nine groups of 15, just past `cJ1_LEAF2_MAXPOP1` = 128, the smallest
shape that reaches the transition — plus four further phases widening to 48
sub-expanses, deleting back below the immediate maximum and refilling (the
decascade path), and repeating the transition one level deeper. Every phase
asserts that `count()`, the iterated key set and the `isset()` hit count all
agree; the bug makes the first over-report while the other two under-report, so
only comparing all three catches it. Validated against a deliberately
miscompiled build:

| libJudy build | result |
| --- | --- |
| gcc-15 `-O3` | **FAILS every phase** — `cascade: count=135 walked=71 isset=87` |
| gcc-15 `-O2` | passes, byte-for-byte against `--EXPECT--` |
| Homebrew (stock sources, clang) | passes |
| Alpine `judy-1.0.5-r1` (aarch64) | passes — a new data point; #131 listed Alpine as an unguarded gamble |

Worth recording: `-Waggressive-loop-optimizations` fires at `-O2` as well as
`-O3` on gcc-15, so **the warning is not the discriminator** — only runtime
behaviour is, which is exactly why a test was needed rather than a build-log
grep. The test needs no vendoring and no build-system change.

### 6.2 #127 — `SEARCH_LINEAR` is a no-op masking a data-loss defect, and the obvious fix makes it worse

**Not reachable today** — php-judy does not build libJudy and no distro passes
`-DSEARCH_LINEAR` — but recorded because it is a trap.

The **outer** defect, `JudyPrivate.h:495`, is a one-character bug:
`#if (! defined(SEARCH_BINARY)) || (! defined(SEARCH_LINEAR))` where `&&` was
meant, so `-DSEARCH_LINEAR` alone still defines `SEARCH_BINARY`, compiles both
macro blocks, and changes nothing. Verified: codegen differs only once the guard
is corrected (71,888 vs 72,515 instructions).

The **inner** defect it hides: the linear variant of `SEARCHLEAFNONNAT` accepts a
`COPYINDEX` parameter and then ignores it, hardcoding `JU_COPY3_PINDEX_TO_LONG` —
always copying 3 bytes regardless of the actual leaf index width. The binary
variant uses `COPYINDEX` correctly. With the guard corrected, 20,000 lookups per
corpus:

| corpus | leaf | binary (correct) | linear |
| --- | --- | --- | --- |
| `struct16` | LEAF3 | 20000 hits / 20000 iterated | 20000 / 20000 |
| `wdense` | LEAF_B1 | 20000 / 20000 | 20000 / 20000 |
| `urand16` | LEAF6 | 20000 / 2000 | **0 / 255** |
| `wsparse` | LEAF6 | 20000 / 2000 | **27 / 256** |

Silent data loss for every leaf index size != 3. No crash, no error return.
**The guard bug looks like a free one-character fix, and applying it alone
converts an inert flag into a data-loss flag.** An earlier recommendation in #113
to do exactly that has been corrected in place.

A mechanical sweep found **no second instance of this class**: every function-like
macro in the tree was checked for accepted-then-unused parameters, and all 60
other hits are intentional (Judy1-vs-JudyL variants, `DBGCODE`, `TRACE_*`,
documentation-only `cPop1`).

### 6.3 #127 — `JudyInsArray.c:284` allocates a zero-word leaf and writes 63 words into it

**ASan-confirmed. NOT reachable from php-judy** — the extension makes zero calls
to `JudyLInsArray` / `Judy1SetArray` / `JLIA` / `J1SA`; this was checked, not
assumed.

`Pjlw = j__udyAllocJLW(Count + 1)` should be `j__udyAllocJLW(Count)`.
`j__udyAllocJLW(Pop1)` indexes `j__L_LeafWPopToWords[Pop1]`, declared with 32
entries (valid 0..31), and the small-array path is entered when
`Count <= cJU_LEAFW_MAXPOP1` = **31** on all four builds. So `Count == 31` reads
index **32**. ASan: `global-buffer-overflow ... READ of size 1 ... 0 bytes after
global variable 'j__L_LeafWPopToWords'`.

In a plain build the out-of-bounds byte reads as `j__L_LeafWOffset[0]` = 0, so
`Words = 0`, `MALLOC(JudyMalloc, 0, 0)` — and the two `JU_COPYMEM`s then write
**63 words into a zero-byte allocation**. It does not crash, and `JLMU` still
reports 504 bytes because that figure is derived from population rather than from
the allocation. Silent heap corruption in a shipped API with **zero coverage in
the shipped `test/`** and no mention in `doc/`; presumably live since 1.0. A
secondary effect of the same off-by-one over-allocates by one `ALLOCSIZES` class
for `Count` in {1, 3, 7, 15, 23} — `Count = 15` needs 23 words and gets 47 — and
`JudyFreeArray.c:86` frees with `Count` words, so allocation and free sizes
disagree. Harmless with stock `JudyFree`, which ignores `Words`; wrong for any
size-aware allocator, i.e. exactly the arena discussed above and in
[#83](https://github.com/orieg/php-judy/issues/83).

### 6.4 `JU_NOINLINE` cannot link

`JudyPrivate.h:589` and `:1532` declare the bit-count and leaf-search families
`extern` under `#ifdef JU_NOINLINE`. **No definition ships anywhere in the
tree**, so any build with `-DJU_NOINLINE` fails to link. The knob exists
specifically to give these helpers external linkage for profiling — precisely
what this investigation needed — and it is unusable as distributed. Definitions
had to be written (bodies copied verbatim from the inline versions) to take the
Amdahl measurement in §5.1 at all.

### 6.5 Smaller upstream items, recorded not acted on

- **`JudyCascade.c:341-380`** — `struct _POINTER_VALUES pv[cJU_NUMSUBEXPL]` is
  declared *inside* the `for (subexp …)` loop, so its lifetime ends each
  iteration, and the OOM recovery loop then reads `pv[]` for previous iterations
  and passes the indeterminate values to a free routine as pointer + size. It
  works only because the compiler reuses one stack slot; `-fstack-reuse`, ASan
  stack-use-after-scope, or any scheduling change breaks it. `j__udyCascade1`
  two functions up shows the correct pattern. OOM-only path, free to fix.
- **`JudyDel.c:371`** — a linear scan bounded only by an `assert`, which
  `JudyPrivate.h` disables via `NDEBUG`. Under `NDEBUG` there is no loop bound at
  all. Inconsistent with the insert path, which uses `j__udySearchLeaf1` on the
  same array.
- **`JudySL.c:52` spells the guard `#ifndef NDEDUG`** — a typo for `NDEBUG`, so
  **every `assert` in the file has been compiled out unconditionally for 20
  years**, including the load-bearing `assert(Pscl == NULL)` at `:429` and
  `:536`. Harmless in production, but those invariants have never been checked by
  anyone's debug build. Anyone auditing the carry-down logic should fix the typo
  first and then run the suite.
- **Dead code, including a block that no longer compiles.** `SUBEXPCOUNTS` is
  referenced in three files, never defined, and `JudyIns.c:527` uses
  `Pjbb->jbb_Counts` where the struct field is `jbb_subPop1`; it also carries two
  precedence bugs. `JU_STAGED_EXP` is self-labelled *"TBD: I think this code is
  broken"* and is — on `j__udyAllocJBU` failure it returns −1 after already
  freeing every BranchB subarray. `NO_BRANCHU` is unreachable.
- **`JU_LITTLE_ENDIAN` is dead machinery.** `configure.ac:176` computes it into
  `config.h`, but **no library source includes `config.h`**. Any endian-dependent
  work must not rely on it: a reviewer's first attempt at the byte-assembly
  optimisation in §7.1 silently took the big-endian path and corrupted the tree.
- **`JU_COPYMEM` is not a `memcpy`.** It is a narrowing element copy and at least
  four call sites depend on that — `JudyCascade.c:601, 635, 753, 838, 841`
  deliberately copy `Word_t` staging into `uint16_t`/`uint8_t` leaves relying on
  implicit truncation. Converting it to `memcpy` produces heap corruption.
  Anyone optimising that file will hit this.

### 6.6 The macOS `JudyMalloc` override cannot bind, and it is not a source defect

Characterised as a link-mode property rather than a libJudy bug, and it bounds
both the arena idea and any future allocator integration:

| link mode | override captures |
| --- | --- |
| static `.a` (Linux, macOS) | **all calls** (25340/25340) — the archive member `JudyMalloc.o` is never extracted because the client already defines the symbol |
| ELF `.so` (Linux) | all calls — default ELF visibility is preemptible, the executable's definition wins via the PLT |
| **Homebrew `libJudy.1.dylib`** | **0 calls** |
| dylib built by libJudy's own libtool | all calls (25340/25340) |

Homebrew's dylib has `MH_TWOLEVEL` set (`otool -h` flags `0x00100085`) and every
intra-image call is a **direct `bl` to the local definition** — `otool -Iv` shows
no indirect-symbol stub for `_JudyMalloc` at all, so there is nothing for dyld to
rebind and interposition is impossible. libJudy's shipped `ltmain.sh` links with
`-flat_namespace -undefined suppress` (flags `0x00100004`), routing every call
through a stub, which is why it works there. `DYLD_LIBRARY_PATH`, client-side
`-flat_namespace` and `-rpath` make no difference; the binding decision was made
when the dylib was linked. **A `JudyMalloc` override can never be relied on
against a system-installed libJudy dylib on macOS**; static-linking a vendored
copy is the only portable route. This is consistent with, and sharper than, the
finding recorded for the same reason in
[`research/shm-arena/FINDINGS.md`](../shm-arena/FINDINGS.md) §3.1.

### 6.7 String-layer defects (JudySL / JudyHS)

Filed at [#127](https://github.com/orieg/php-judy/issues/127#issuecomment-5324254966).
Two matter for a vendored copy; the rest are recorded for completeness.

- **`~0UL` breaks silently on any LLP64 port.** `JudySL.c:839` in
  `JudySLPrevSub` uses `indexword = ~0UL`, correct only because `Judy.h:94`
  typedefs `Word_t` as `unsigned long`. Where `unsigned long` is 32-bit and
  `Word_t` must become `uintptr_t`, this yields `0x00000000FFFFFFFF`, so
  `JudyLLast` never sees an index word above 4 G — i.e. **every key of 5 or more
  leading characters** — and `JudySLPrev`/`JudySLLast` return wrong results with
  no error. **This is not hypothetical: php-judy's Windows CI already sed-patches
  this class** ("`~0UL` is 32-bit on MSVC x64, use `~(size_t)0`"; "`1UL << N` → 0
  when N >= 32"). Whether this specific site is covered by the existing patch set
  should be checked.
- **Signed-shift UB in both 32-bit word-packing macros** (`JudySL.c:158`,
  `JudyHS.c:176`): `uint8_t` promotes to `int` and `x << 24` exceeds `INT_MAX`
  for any byte >= `0x80`. The 64-bit siblings get it right, so this is a
  transcription slip confined to the 32-bit paths. **Reachability currently nil**
  — CI is x64-only and MSVC x64 patches `Word_t` to 8 bytes — but it is a hard
  stop under `-fsanitize=undefined`.
- Also: inverted errno classification in `JudySLPrevSub`/`JudySLNextSub` (a
  corruption at level 3 reports `JU_ERRNO_NOTJUDYSL` rather than
  `JU_ERRNO_CORRUPT`, with a dead ternary hiding it); a commented-out pointer
  clear at `JudyHS.c:664` leaving a dangling tagged pointer behind a `JudyFree`;
  null-pointer arithmetic at `JudySL.c:542`; and **JudySL invalidating value
  pointers more aggressively than JudyL and documenting it nowhere** — carrying
  an existing SCL down frees it, so a `PPvoid_t` the caller holds *for a
  different key* goes dangling. Not a live bug for php-judy, which consumes every
  slot immediately, but the `mirror_payload` design is the nearest thing to
  caching a slot across an insert and this deserves an invariant note.

The same review's **performance** findings — including a refutation of its own
load-bearing premise — are in [§7.7](#77-the-string-layer-performance-review-refuted-its-own-premise).

## 7. Findings — negatives

These matter as much as §5 and §6, and are recorded so nobody re-runs them
expecting a different answer. The source-review negatives are filed at
[#113 (negative results)](https://github.com/orieg/php-judy/issues/113#issuecomment-5324250328);
their method is the 1.0.5 tree built 8 ways (gcc-15 at `-O0`/`-O1`/`-O2`/`-O3`/`-Os`,
Apple clang at `-O2`/`-O3`, clang `-fsanitize=address,undefined`) plus
differential and sanitizer stress harnesses over Judy1/JudyL/JudySL/JudyHS
covering every index size 1–7, every immediate type, and cascade/decascade.

### 7.1 The "second out-of-bounds class" was a false positive

Round 1 reported that `-Wstringop-overflow` warnings survive Baskins' widening
patch at `-O3` and framed them as an uncharacterised second out-of-bounds class.
**That framing was wrong.**

Read the counts carefully — **two different numbers happen to both be 91**, and
conflating them inverts the meaning:

| | compiler | stock | after the widening patch |
| --- | --- | --- | --- |
| round 1 (POC) | gcc 11.4 | 7 / **91** / 182 at `-O2` / `-O3` / `-O3 -flto` | **84** at `-O3` |
| refutation | gcc 15 | **200** | **91** |

Round 1's 91 is a *stock* count. The refutation's 91 is a *patched* count — the
same population as round 1's 84, re-measured on a different compiler. **The
refutation applies to the patched set only.** Counts differ by compiler; the
conclusion does not.

All 91 survivors are refuted:

1. **They are all JudyL, and all name the same object** — `j_pi_LIndex`, size 7.
   Zero Judy1 warnings survive the patch. Groups: `j__udyCopyWto3` at
   `JudyLCascade.c:135` (38), `JudyLInsArray.c:445/432` (46), `JudyLDel.c:1026`
   (7).
2. **The bound is provable by inspection.** Every JudyL immediate fits in <=7
   bytes: `cJL_IMMED1_MAXPOP1` = 7 → 7 bytes, `2_03` = 3 × 2 = 6, `3_02` =
   2 × 3 = 6, and `4..7` use `_01`, which stores the index in `jp_DcdP0` and the
   value in `jp_Addr` so `jp_LIndex` is never written. Maximum possible write is
   7 bytes into a 7-byte array — an exact fit with zero margin. Overflow is
   *impossible*, not merely unobserved. (Contrast Judy1, where
   `cJ1_IMMED{1,3,5}_MAXPOP1` all demand 15 bytes from an 8-byte array. That is
   the real defect and the patch is exactly right.)
3. **The mechanism is destination mis-attribution across merged call sites.** GCC
   reports arithmetically impossible offsets — up to `[44, 51]` into a 7-byte
   object at struct offset 8, which would be byte 59 of a 16-byte `jp_t`. For the
   Cascade group, `-fno-inline` drops all 38 to zero: GCC inlines `static
   j__udyCopyWto3` at two call sites (one writing `cJU_LEAF4_MAXPOP1` indices
   into a fresh leaf, one writing <=2 into `jp_LIndex`) and pairs the larger trip
   count with the smaller object. The `JudyLInsArray` and `JudyLDel` groups
   survive `-fno-inline` because their merge is intra-function tail-merging
   rather than the inliner — same failure, different pass.
4. **ASan is clean** over a delete-heavy stress with keys biased to `0xFF` bytes
   (so the JP being rewritten is frequently the last element of its allocation,
   which is the only configuration where an overrun of the claimed size would
   leave the malloc'd block), 40 rounds × 160k ops, both Judy1 and JudyL: zero
   findings, `J1C`/`JLC` consistent with walk counts throughout.

**Two limits to state, because they are the difference between a refutation and
a proof.** First, **ASan cannot see intra-object overflow** — a Judy1 write from
`jp_1Index[0]` through `[14]` stays inside the 16-byte `jp_t` and is invisible to
it, which is precisely why the real Judy1 defect went undetected for ~19 years.
ASan is informative here only because the offsets GCC claims for `j_pi_LIndex`
*would* leave the allocation. **The refutation therefore rests primarily on the
bound argument, with ASan as corroboration on exercised paths — not on ASan, and
not as a proof for unexercised paths.** Second, this is a bound plus proof for
exercised paths, not a formal proof for every path.

**The `jp_1Index` widening patch is not optional.** These are two separate
findings about closely related arrays: **the surviving *diagnostics* are
spurious; the *defect* is live and silently loses keys at `-O3`** (§6.1). A
reader who takes "false positive" to mean "the patch can be skipped" has drawn
exactly the wrong conclusion.

(`JudyGet.c:721`'s `CHECKLEAFNONNAT(5, Pjp, Index, 3, ...)` reading
`jp_1Index[10..14]` is another instance of the *known* Judy1 class from #131, not
a new one — UBSan confirms `index 10 out of bounds for uint8_t[8]`.)

### 7.2 OOM paths are sound

Two different experiments with two different injection mechanisms, which the
shorthand "720 + 80" compresses. Stating them separately:

- **720 trials via libJudy's own `j__uMaxWords` failure hook** — 6 seeds × 60 cap
  values × 2 APIs (JudyL, Judy1). The `MALLOC` macro in `JudyMallocIF.c` is
  `(((WordsPrev) > j__uMaxWords) ? 0UL : MallocFunc(WordsNow))`, so setting the
  cap below the array's current `jpm_TotalMemWords` makes every subsequent
  internal allocation return 0 — identical to a real malloc failure, injected
  below every caller and above `malloc`. Caps swept 1000..60000 in steps of 1000,
  so failure lands at 60 different points in the allocation sequence. Per trial:
  build 20,000 random keys uncapped recording every key inserted, apply the cap,
  attempt 20,000 more inserts (most returning `PJERR`/`JERR`), lift the cap, then
  verify.
- **80 distinct injection offsets across JudyL/JudySL/JudyHS** (240 runs, 80 per
  API) with an **interposed** failing `JudyMalloc`/`JudyFree` counting live
  allocations and returning 0 from the Nth call after a baseline, N = 0..79. A
  different and stronger mechanism, and a necessary one: **JudySL and JudyHS
  allocate through `JudyMalloc` directly and are not covered by `j__uMaxWords` at
  all.**

**Result across all 140 distinct injection points: zero inconsistencies, zero
leaks.** Every pre-OOM key still present with the right value; forward-walk count
equal to the recorded key count; `JLC`/`J1C` matching a full walk; the live
allocation counter back to exactly zero after `JLFA`/`JSLFA`/`JHSFA`.

Corollary worth recording: `Judy.h`'s
*"TBD: no guarantee the Judy array has no memory leaks upon JU_ERRNO_NOMEM"*
appears to be **stale pessimism**. This matters for php-judy because OOM is
reachable from PHP under `memory_limit`-adjacent conditions.

Two reproduction gotchas: `Judy.h`'s default `JUDYERROR` macro calls `exit(1)`,
so any OOM harness must define it to a no-op first or it aborts on the first
injected failure instead of testing recovery; and the interposed-`JudyMalloc`
experiment is **only reproducible against a static link**, per §6.6.

### 7.3 Strict aliasing is not being exploited — `-fno-strict-aliasing` is NOT warranted

Type-punning is pervasive (`P_JP`/`P_JPM`/`P_JLL` casts; `jp_1Index` read through
`uint16_t*`/`uint32_t*`), and the `uintN_t*` reads of a declared `uint8_t[]` are
technically UB. Empirically it is not exploited: gcc-15 `-O3 -fstrict-aliasing`
against `-O3 -fno-strict-aliasing`, two builds from the same patched tree
differing only in that flag, produced **byte-identical results across 7 seeds of
a 14-pattern differential harness**.

"Byte-identical" here means **harness output**, not binaries and not object
files: a 64-bit rolling hash over the complete key sequence from a forward and a
reverse walk, with `JLC`/`J1C`, walk count and net insert count folded in. All 7
seeds match exactly.

**Limits, which are wide.** The harness is **Judy1 only** (`J1*` entry points);
the same comparison was not run for JudyL, JudySL or JudyHS, nor on x86-64, nor
with clang. What the data supports is *"not exploited by gcc-15.2.0 at `-O3` on
arm64-apple-darwin for the Judy1 path"* — not *"not exploitable"*. The stronger
recommendation rests on that **plus** a code-inspection argument: allocations are
raw `malloc` with no declared type, accesses are type-consistent per JP type, and
the `jpo_t`/`jpi_t` punning goes through a union, which GCC and Clang both
explicitly support. It is not a proof and is not recorded as one.

Conclusion nonetheless: `-fno-strict-aliasing` would cost optimisation
`(projected)` and buy nothing measurable. **Do not add it.** This closes work
item 2 of the round-1 plan.

### 7.4 Everything else that was tried and did not pay

- **`j__udyCountBitsB` is closed.** See §5.1 — real, exercised, and worth 0–2.4%
  with a CI that includes 1.0.
- **Compiler flags are exhausted.** See §5.3. `-O2` is correct; `-O3`, LTO and
  PGO are neutral-to-negative; the visibility hypothesis that would have
  reopened it was tested and is wrong.
- **`GLIBC_TUNABLES` cannot bound the arena on glibc**, and the allocator
  mechanism reaches under 0.3% of nodes. See §5.4.
- **libJudy does NOT redundantly re-descend on state transitions.** After a
  cascade or branch promotion the `goto ContinueInsWalk` re-enters the switch and
  re-runs `JU_CHECK_IF_OUTLIER` plus the digit extraction, but it does **not**
  re-walk from the root, and the switch compiles to a single indirect branch
  (confirmed: exactly one jump table in the function). The analogy to php-judy's
  own `mergeWith` re-descend (#121) does not hold — this is a handful of
  instructions at a state transition, not a repeated traversal.
- **Structural leaf↔branch hysteresis is correct and does not thrash.**
  `JU_BRANCH_KEEP` (`JudyDel.c:167-176`) compresses branch→leaf only when the
  pre-delete `pop1 == MaxPop1`, while insert cascades at `MaxPop1 → MaxPop1+1`.
  Alternating insert/delete across that boundary keeps the branch. Near-optimal
  for its design.
- **Conditional branch prediction is not the problem.** Conditional branches
  predict at 0.0002%–3.5%: the leaf binary search is not a misprediction source.
  The indirect `jp_Type` switch mispredicts at 43–77% *in cachegrind's model*,
  and that was **explicitly not claimed as a cost** — the simulator's predictor is
  bimodal, not Alder Lake's ITTAGE, and with no PMU this stays "the model says
  look here". §9.2 records why it can now be closed on mechanism instead.
- **Endianness handling is deliberate and correct.**
  `JU_JPDCDPOP0`/`JU_JPSETADT`, `JU_COPY{3,5,6,7}_*`, `JU_SETDIGIT`/`JU_SETDIGITS`
  are byte-at-a-time or mask/shift in registers, and `jp_Type` lives in a
  `uint8_t` array slot rather than a bitfield. `COPYSTRINGtoWORD` builds the word
  MSB-first, so JudyL's numeric order equals lexicographic byte order on every
  host; JudyHS deliberately packs LSB-first, which is correct because JudyHS is
  unordered.
- **`JudyTables.c` cannot silently mismatch.** `Judy{1,L}Tables.c` are generated
  by compiling and running a generator against the same headers the library uses,
  and the generator `exit(1)`s if `ALLOCSIZES` cannot cover an object. (It does
  break cross-compilation — §10.1.)
- **API edge cases are correct**: `J1C`/`JLC` on empty arrays and reversed
  bounds, `J1N` from `~0`, `J1P` from `0`, `J1FE`, `J1BC(0)`, `J1BC(n > pop)`,
  `JLBC`, and keys at `0` and `~0`.
- **The 32-bit path is not obviously bit-rotted** — all `-UJU_64BIT` translation
  units syntax-check clean. **Syntax only**: a true 32-bit `Word_t` configuration
  was never built or run.

### 7.5 Allocator size-class shrink hysteresis: characterised, and declined

At a population where `PopToWords[N] != PopToWords[N+1]`, insert allocates and
copies up while delete allocates and copies back down — a full alloc + copy +
free in **both** directions, forever, for a population parked on a boundary. For
a JudyL Leaf2 at pop ~51 that is ~600 bytes copied per operation.

The header comment at `JudyDel.c:39-47` already names it, and **the original
authors' position is the right one**: a node's allocated size is a pure function
of its stored `pop1`, there is no stored capacity, and there is nowhere to put
one — `jp_DcdPopO` is full and the JP-type enum is saturated. Adding hysteresis
means a capacity field costing memory on **every** node to save copies on a
minority. Recorded as **declined**, not as an opportunity — though it is exactly
the failure mode a fixed-size cache would hit if its steady-state population
parks on a boundary.

### 7.6 Two write-path items that are real but unmeasured in time

Recorded separately because their status differs from everything above: the
code-size figures are `(measured)` compiler output and therefore immune to
machine load, but **no timing was ever run** (the box was at load 9.32 on 8
cores), so what they buy in time is unknown. Both were verified bit-identical
against a differential harness driving Judy1 and JudyL through ~2M mixed
ins/del/get/count/traversal operations over 8 key distributions, checksumming
every returned value plus final counts and `J1MU`/`JLMU`, plus the shipped
`test/Judy1LCheck.c`, plus ASan builds.

1. **`JU_JPDCDPOP0` / `JU_JPSETADT` assemble a word one byte at a time.**
   `jp_DcdPopO` is stored as `uint8_t jp_DcdP0[7]` big-endian and read with 7 byte
   loads + 6 shifts + 6 ORs — a portability workaround for a bitfield that would
   not pack under MSVC, sitting on the library's hottest path
   (`JU_DCDNOTMATCHINDEX` expands at 31 sites in `JudyGet` alone, and
   `JU_JPSETADT` on the pop-increment at every level of every successful insert).
   Neither compiler merges the loads. `(measured)`
   `JU_DCDNOTMATCHINDEX(...,2)` goes 13 → 6 instructions on clang/arm64,
   21 → 11 on clang/x86-64, 19 → 6 on gcc-15/arm64. The instruction count is not
   the point: **5 dependent byte loads collapse to 1 word load**, shortening the
   load-to-use chain per descend level.
2. **The shift macros auto-vectorise into ~6 KB of never-taken dispatch.**
   `(measured)` `j__udyInsWalk` compiles to 18,980 B / 4,745 instructions and
   `j__udyDelWalk` to 22,060 B / 5,515. Clang emits a 4-way vectorised dispatch
   for each of ~14 macro instantiations, but Judy leaves never reach those trip
   counts (64-bit JudyL maxima: Leaf1 = 13, Leaf2 = 51, Leaf3 = 46, LeafW = 31).
   A per-loop `JU_NOVEC` pragma gives −32.6% / −38.5%; combined with item 1,
   **`j__udyInsWalk` −40.1%, `j__udyDelWalk` −38.9%, library `__text` −18.8%**,
   with **zero** added libc calls. The `memmove`/`memcpy` rewrite is bigger on
   paper but adds 71 + 68 libc calls on shifts that are often 2–20 elements —
   take the pragma, not the rewrite.

Falsification conditions, stated: item 2 dies if the production compiler does not
vectorise these loops (check `j__udyInsWalk`'s size in the real build — ~13 KB
rather than ~19 KB means it already is not happening), or if `InsWalk`'s I-cache
footprint is not a miss source, which an L1i counter would settle and no PMU is
available to provide.

> **Outcome (2026-08-18): item 2 was run as Stage 3 O2 and DROPPED on
> measurement** ([#142](https://github.com/orieg/php-judy/issues/142), O2 gate
> comment). Both falsification conditions effectively fired. On gcc the first
> fired outright: gcc ≤ 11 does not vectorise at `-O2` at all, and gcc 14.2's
> `-O2` very-cheap-cost-model residue sits outside these macros —
> `j__udyInsWalk` is already at the "not happening" size (~13.4 KB), so the
> shipped flag pinning (Stage 1's `-O2 -fno-unroll-loops`) had ALREADY avoided
> the tax on every gcc build. On clang the dispatch is real under shipped flags
> and the pragma removes 21-32% of code size (Apple clang 21 arm64 and Debian
> clang 19 x86-64 both measured on the current tree), **but the time gate on
> clang 19/x86-64 (honeycomb, Docker, O1/O3 protocol incl. comment-only control
> and a new randomized-order full-delete phase) came back null-to-negative: no
> cell met CI-low > 1.0, and delete REGRESSED 1.3-2.6% (CI-high < 1.0, clear of
> the control) on three of five corpora, GET null everywhere.** The premise
> error, recorded: "never-taken dispatch" was true of the 32-wide interleaved
> loops measured here on pristine `-O2`, but `-fno-unroll-loops` already
> deletes those; what the pragma additionally removes includes narrow 2-word
> vector loops whose entry guards Judy's shift lengths DO satisfy (documented
> leaf maxima 13-51, average shift about half) — the mechanism hypothesis
> consistent with the measured sign (no PMU; not a counter measurement) is
> that those narrow loops were doing the shifts at half the iterations, so
> the review measured the I-cache cost of the wide paths and never the
> compute benefit of the narrow ones ("what it buys in time is unmeasured"
> was the load-bearing caveat above). The code-size win is real; the time
> win does not exist. Item 1 (bswap) shipped as O3 and is unaffected.

> **Adversarial re-review (2026-08-18): the O2 drop is UPHELD.** A synthetic
> internal panel (three parallel reviewers — adversarial brainstorming and
> self-bias detection per §8, **not** external peer review; see §11.10)
> attacked the drop on five surfaces and upheld it, while finding **two
> documentation errors** in the record around it and adding two new facts:
>
> - **New fact (a): the icache-pressure regime, unmeasured at gate time, has
>   now been measured** (clobber harness; artifacts honeycomb
>   `/var/tmp/jp113/o2/icc/`). Null at n=10^6; insert remains
>   regression-leaning even under L1i flushing every 8 ops. The one regime
>   the gate had not covered does not rescue the patch.
> - **New fact (b): the arm64 evidence base narrowed, same verdict.** Apple
>   clang 21 emits the same VF=2 narrow NEON loops with 1–16-element entry
>   guards — the structural mechanism transfers — and local arm64 timing was
>   contaminated but same-sign. The honest limit ("clang-on-arm64 never
>   time-gated cleanly") stands, but it is smaller than the record implied.
> - **Record correction 1**: the O2 gate comment on
>   [#142](https://github.com/orieg/php-judy/issues/142) called the
>   out-of-cache run "the regime where the I-cache argument had its best
>   shot". Wrong — that run pressures the **D-side** only (data footprint
>   ~330 MB; the hot code still fits L1i). The I-cache argument's actual
>   best shot was the L1i-clobber regime in (a), which is null.
> - **Record correction 2 (control-arm semantics)**: a ctl arm built from a
>   comment-only edit with the same link seed produces binaries
>   byte-identical to pre — it is a **run-order/temporal control**, not a
>   code-layout control. The layout control in this design is the 5
>   randomized link orders *within* each arm. The floor such controls
>   calibrate is revised in §11.10.

`JudyLInsArray` was audited alongside and is **correct** — a 126-case
differential (6 key distributions × 21 sizes to 300,000) comparing full ordered
traversal of keys and values, `JLC` and `JLMU`, all passing once the §6.3
off-by-one is fixed, with memory occasionally *better* than incremental insert
(1,599,768 vs 1,774,520 bytes at n = 65,536 random 64-bit keys, −9.8%). Its
blockers are API-level: `*PPArray` must be NULL, so `$a->mergeWith($b)` would
have to rebuild all of `$a`; input must be strictly ascending with no duplicates;
it needs two `Word_t[Count]` scratch arrays; and it has zero upstream test
coverage. For `mergeWith` it is a design change, not a drop-in.

### 7.7 The string-layer performance review refuted its own premise

Filed at
[#113 (string-layer performance)](https://github.com/orieg/php-judy/issues/113#issuecomment-5324314505).
**All source-derived and unmeasured** — every quantity below is `(projected)`
with its reasoning, and none carries a confidence interval.

**The premise that motivated the review was wrong, and several downstream
proposals rested on it.** The hypothesis was that JudySL pays a cold
pointer-chase out to a separately-`malloc`'d key tail, and that inlining the tail
(as ART, HOT and Masstree do) removes a hop. It does not. `scl_t`
(`JudySL.c:184-188`) holds `scl_Pvalue` and `scl_Index[]` in **one allocation**,
and for tails <=24 bytes the same cache line; `JudySLGet` returns
`&PSCLVALUE(PSCL)` — a pointer *into the object the `strcmp` just touched*.
JudyHS's `ls_t` has the identical layout and the identical property. **Hop count
is a wash; inlining the tail buys nothing.**

**The real gap is that JudySL has tail compression but no path compression.** The
`scl_t` fires only when one key remains beneath (`:408`); a prefix shared by
*many* keys is never compressed and costs **one JudyL array per 8 bytes of shared
prefix**, each a separate `malloc` and each a dependent load on every lookup.
Upstream concedes it — *"shortcut branches are worth considering too"*
(`JudySL.c:42`), an unimplemented TBD from 2005. `(projected)` hop counts at
~10^6 keys: ~5 dependent loads for random 16-byte keys against ~9 for a 32-byte
shared prefix, i.e. **~1.8× on prefix-heavy corpora** — and namespaced cache keys
(`app:v2:user:12345:profile`) are exactly that shape. This also sharpens rather
than weakens the Masstree comparison in
[BACKEND_EVALUATION.md](../../BACKEND_EVALUATION.md): same 8-byte-slice
decomposition, but Masstree path-compresses within each slice layer *and*
supports a cursor. Those two, not the tail, are the delta.

**A cursor for #85's 25× iteration gap is addable, layout-neutral, and upstream
specced it.** `JudyPrevNext.c:251-253` already maintains `APjphist[]` /
`Aoffhist[]` — exactly the state a cursor needs — rebuilds them from the root on
every call and discards them on return, and `:156-161` states the intent verbatim
as a TBD. The cursor would be **caller-owned state (~150 bytes) with no
in-memory layout change**, so a cursor-enabled build stays data-ABI-compatible.
If the previous index was found at offset `o` in a leaf of population `p`, `Next`
is offset `o+1` whenever `o+1 < p` — a sorted-array read, no descend, no cache
miss — so for average leaf population `L`, ordered iteration goes from `N` full
descents to `N/L` descents plus `N` array reads. **No multiplier is attached and
the item is gated**: instrument `JudyLNext` to histogram `pop1` at the found leaf
over a representative walk. If average leaf population is small — dense integer
expanses become bitmap/uncompressed branches with 1–3 entry `Immed` leaves — the
win collapses. That single number decides the design.

**`JudyHSDel` hashes and descends the key twice — now verified at source.**
Recorded in [PR #132](https://github.com/orieg/php-judy/pull/132) as an
*unverified* upstream observation because the agent that found it had no
`JudyHS.c` available; **that caveat is now discharged.** `JudyHSDel:20` calls
`JudyHSGet` (full hash + two `JLG` + `memcmp`), then `:25`/`:32`/`:36` redo the
length lookup, the **full hash again**, and the bucket lookup. `JUDYHASHSTR` is a
byte-at-a-time `c*31 + b` loop with a serial dependency, so that is two full
passes over the key bytes plus four JudyL descents to delete one entry; the
author's own comment at `:575` concedes it. `(projected)` ~40% of `JudyHSDel`'s
non-free work at 16–40 byte keys. php-judy already took the half it could reach —
PR #132 removed our own redundant `JHSG` before `JHSD` on the
`STRING_TO_INT_HASH` unset path, taking it from three hash passes to two. **The
remaining two are both inside libJudy**, so they land only via vendoring.

Three further redundant key passes are free and layout-neutral: `JSLN`/`JSLP`
call `STRLEN(Index)` on every iteration step and read the length in exactly one
place, a test equivalent to one `JudySLGet` already relies on without calling
`strlen` at all; `JudySLIns` makes three passes over key bytes on an overwrite
where `STRCMP` alone decides equality; and `JudySLDelSub:642` recomputes a length
it was handed. None of these touch #85's 25×, which is pointer-chase cost rather
than linear-scan cost.

> **Outcome (2026-08-18): run as Stage 3 O4 and gated per patch on honeycomb**
> (x86-64 gcc 11.4; 3 arms x 5 randomized-link-order builds, interleaved
> pinned trials, per-build medians, percentile bootstrap over builds, CI-low
> \> 1.0 + control-calibrated claim floor; [#142](https://github.com/orieg/php-judy/issues/142)
> O4 gate comment). **Three of the four merged, one dropped:**
>
> - **O4a** (`JSLN`/`JSLP` strlen removal): `(measured)` ordered-walk
>   speedups x1.0324/x1.0471/x1.0501 (struct16/struct32/urand16, CI-lows
>   1.0264/1.0378/1.0161), reverse walks x1.0294-x1.0400, out-of-cache
>   x1.0208/x1.0209 — controls null. MERGED.
> - **O4b** (`JudySLIns` 3→1 passes): `(measured)` overwrite x1.0448
>   (struct32, CI [1.0354,1.0542]) and x1.0850 out-of-cache (struct16,
>   CI [1.0777,1.0890]), controls x0.9973/x0.9987. MERGED.
> - **O4c** (`JudySLDelSub` `SCLSIZE(STRLEN())` recompute): **DROPPED at the
>   gate.** Exact and zero-risk by construction — the interposed-allocator
>   parity harness confirms byte-identical allocation totals and per-free
>   sizes — but **null in time on every delete cell**: best cell struct16 L3
>   x1.0321 [1.0009,1.0410] sat on a contaminated control (ctl x1.0297),
>   out-of-cache x0.9971; nothing cleared the claim floor. One strlen over a
>   <=word-ish tail inside a ~350-770 ns delete is below the measurement
>   floor, and per the house rule (null => drop, not "merge anyway", as with
>   O2) it stays out of the vendored tree.
> - **O4d** (`JudyHSDel` single hash+descend): `(measured)` delete
>   x1.0625/x1.0672 (hstruct16/hurand16 L3, CI-lows 1.0544/1.0527), x1.0382
>   out-of-cache (CI [1.0324,1.0423]) — controls null. The `(projected)`
>   ~40% for the full non-free work was optimistic; the measured ~6% whole-op
>   effect is what two saved JudyL descents + one saved hash pass buy at
>   16-byte keys. MERGED.
>
> Equivalence evidence for the merged three: 48-cell differential-fuzz grid
> (plain + ASan/UBSan library, incl. a new engineered-hash-collision corpus
> that watched-to-fail catches a guard-stripped O4d at op 34), length-sweep
> `tests/string_key_length_sweep_001.phpt`, byte-identical `JudyMalloc`
> accounting across arms, and 221/221 .phpt on macOS + Alpine/musl.

> **Adversarial re-review (2026-08-18, synthetic internal panel — §11.10):
> the merged patches stand, but the claim set shrinks under the revised
> ~3% L3-resident claim floor, and this round under-disclosed its control
> contamination.**
>
> - **Under-disclosure correction, stated plainly**: SIX of this round's L3
>   control cells carried excursions ≥2% (struct16 get +2.40%, struct16
>   overwrite +2.59% — with a CI excluding 1.0 — struct16 del +2.97%,
>   varlen get −2.26%, varlen riter +2.48%, varlen iterate +1.99%). The PR
>   and the gate comment disclosed only two of them (struct16 overwrite and
>   del, flagged "contaminated"). The raw CSVs
>   (`/var/tmp/jp113/o4/o4-bench-l3.csv`) contained all six; the write-up
>   should have.
> - **RECLASSIFIED, not claimed**: O4b `urand16` overwrite ×1.0188 and
>   `varlen` overwrite ×1.0145 — both inside the demonstrated L3 noise
>   envelope (§11.10).
> - **RECLASSIFIED, artifact-risk**: O4a `urand16` iterate ×1.0501 — the
>   worst-case build pairing reverses it to ×0.9863, with a 6.1% pre-arm
>   between-build spread in that cell.
> - **Flagged, artifact-risk**: the O4a struct16 L3 iterate/riter family
>   (×1.029–1.036) — the same corpus family carried the +2.4–3.0% control
>   excursions above in the same run.
> - **Survives**: O4a `struct32` iterate ×1.0471, the O4a out-of-cache
>   walks (×1.0208/×1.0209, against the ~1.3% out-of-cache floor that does
>   hold), O4b `struct32` overwrite ×1.0448 and struct16 out-of-cache
>   ×1.0850, and both O4d cells plus O4d out-of-cache. The O4c DROP is
>   unaffected (it was already declared null).
>
> No patch is unmerged by this: the surviving cells still clear the gate
> for O4a/O4b/O4d. What changes is which cells the record may *cite*.

**Three things that look like targets and are not — do not chase them.**

- **The `memcmp`/`strcmp` DRAM attributions are not shavable.**
  `__memcmp_avx2_movbe` at 8.7% and `__strcmp_avx2` at 5.9–6.4% of LL misses do
  **not** say the comparison is expensive — they say the `ls_t`/`scl_t` line is
  cold and the compare is merely the first instruction to touch it. The very next
  read is the value, in the same allocation and usually the same line, so the
  miss is paid **to reach the answer**, not to verify the key. Skipping the
  compare would mean trusting a 32-bit non-cryptographic hash for equality.
  Flagged explicitly because "6.4% in `strcmp`" reads like a target and is not
  one.
- **JudyHS's hash is `c*31 + b`, not the Bob Jenkins hash its comment credits —
  and it should not be "fixed".** The output feeds `JudyLIns`/`JLG` as a
  radix-trie index, not a modulo bucket array, and Judy *rewards* clustering with
  better compression. Better avalanche means less clustering means worse JudyL
  compression: **trading away the one axis this project measurably wins.**
  Correct the comment, leave the hash. Worth noting for `SECURITY.md` that
  collisions fall through to a JudyL word-trie rather than a linked list, so `k`
  colliding keys cost `O(len/8)` descents each rather than `O(k)` — collision
  resolution is already sub-linear by construction.
- **Short keys already take a hash-free fast path.** For `Len <= WORDSIZE` JudyHS
  skips the hash entirely, allocates no `ls_t` and performs no `memcmp` — two
  JudyL lookups, with the packed key word acting as a perfect hash. That
  threshold **coincides exactly with php-judy's `*_ADAPTIVE` SSO threshold**, so
  for keys <=8 bytes `ADAPTIVE` and plain `HASH` are doing nearly the same thing.
  **Raising php-judy's SSO threshold above 8 would stop tracking JudyHS's own
  structure and start costing more than it saves.** This is consistent with the
  measured SSO result in [`research/README.md`](../README.md), where SSO-packed
  and `JudyHS` came out within noise of each other at 6-byte keys.

One accounting caveat with a php-judy consequence: `SCLSIZE(len)` is
`1 + ceil(len/8)` words, so a tail of <=8 bytes is a 16-byte request, which glibc
serves from a **32-byte** chunk. Same for `ls_t`. **True per-unique-key overhead
is ~2× the computed figure**, so if `memoryUsage()` estimates string-type memory
from `SCLSIZE` it is under-reporting — worth checking against RSS before any
memory claim.

## 8. The adversarial review round

The retraction in §4 was produced by **five synthetic expert review lenses plus a
blind four-persona panel**. Recording the shape honestly:

- **These are synthetic personas, not peer review.** They function as adversarial
  brainstorming and self-bias detection. Their convergence is signal; it is not
  external evidence, and nothing below was accepted without being measured.
- **The panel run was partial** — phases 0 and 1 only. The review lenses served
  the challenge and adjudication roles the later phases would have.
- Reviewer claims about AMAC / coroutine-interleaving prior art were flagged **by
  their own author as recalled rather than verified**, and nothing in this record
  rests on them.

The convergence worth recording: **four fields blind to each other independently
named the same allocator baseline and the same interleaving mechanism.** That is
what promoted MLP from an aside to the round-2 headline, and MLP then measured at
×1.62–1.79 (§5.2). It is also what put the `GLIBC_TUNABLES` probe ahead of
building an arena — and that probe is what showed the arena hypothesis was weak
(§5.4). The panel earned its place by redirecting the measurement, not by
supplying conclusions.

## 9. The external review round-trip

A research scientist proposed a full modernization: 64-byte-native node geometry,
SIMD node scan, tagged pointers via 48-bit VA packing, optimistic concurrency
control, and a from-scratch Rust rewrite. It was audited against this project's
measurements and against the 1.0.5 source (including a compiled `sizeof` probe),
not against its citations.

### 9.1 Premise audit — what our measurements supported, contradicted, or did not address

| claim | verdict |
| --- | --- |
| a hardcoded 128-byte cache-line assumption "doubles memory latency per lookup" | **Constant supported; consequence contradicted; remedy a no-op for 3 of 4 node types.** `JudyPrivate.h:289` really does define `cJU_BYTESPERCL 128`, but its entire use-chain ends at `CLSPERJPS()` in `JudyCount.c:536`, one direction heuristic deciding whether `Judy*Count()` accumulates up or down. **It sizes no node, no leaf, no branch** — that is `J_L_MAXB` (512 B), `J_1_MAXB` (256 B) and `ALLOCSIZES`. A compiled probe gives `jbl_t` 120 B, `jbb_t` 128 B, `jbu_t` 4096 B: **three of the four proposed geometries are byte-for-byte what libJudy already ships**, and the fourth ("LinearBranch4 = 64 B = 8 B header + 4 × 16 B JPs" = 72 B) is arithmetically self-contradictory. Baskins hedged for 64-byte lines in 2002 — `JudyPrivateBranch.h:184` picks `cJU_BRANCHLMAXJPS 7` for "a 1-cacheline sized structure", and `JudyL.h:320-323` interleaves the bitmap leaf precisely to avoid wasting a fill "on systems with smaller cache lines than the assumed value". |
| SWAR costs "5–15 cycles per bitmap traversal" | **Splits.** Cycle count roughly right (delta ~12–15 against a 3-cycle POPCNT). `CountBitsB` **closed**; `CountBitsL` **real** at 17% cache-resident. Zero popcount calls on realistic string corpora. See §5.1. |
| "high branch misprediction on search loops" | **Contradicted.** Conditional branches predict at 0.0002–3.5%. And the proposed SIMD remedy targets the *linear-branch digit scan* — but `BRANCH_L` is never produced by any realistic corpus. The site that could matter is the *leaf* search, which the proposal does not name. |
| "high internal fragmentation / false sharing / no HugePages" | **Fragmentation contradicted in magnitude** (nodes above the tcache ceiling are 0.22–0.29% of all nodes). False sharing needs threads and there are none. **HugePages genuinely untested** — not addressed either way. |
| "16 unused upper VA bits; high pointer overhead" | **Premise mis-describes the JP.** The 7 bytes are not waste: they carry the skipped decode bytes (level compression) and population−1. An 8-byte "CompactJP" deletes both — removing level-skip verification and the population accounting `populationCount($lo, $hi)` rides on. Feature removal presented as compression. |
| thread contention / OCC / EBR | **Irrelevant here.** One array per PHP process, one thread. [#83](https://github.com/orieg/php-judy/issues/83) closed the shared-arena question. OCC would add a version load and two acquire fences to a read path whose contention is structurally zero. |

Both headline KPIs were unfalsifiable as written. **"<15 ns for 64-bit random
keys"**: a Judy descend is a dependent chase of 2.0–4.0 levels, and past L3 each
level is a DRAM round trip that cannot start until the previous lands — no
pointer-chasing structure reaches 15 ns for an uncached random key, and neither
does a perfect single-probe hash table. The target therefore implies an
L3-resident working set, where the serial path measures 18.59 ns and ×0.83 (best
popcount-L) is **15.4 ns `(projected)`** — at the threshold, not under.
**"<9.5 bytes/key"** is type-ambiguous to the point of meaninglessness: JudyL
stores an 8-byte value word per key, leaving <=1.5 bytes/key for the entire trie,
while Judy1's `LEAF_B1` is 32 B per 256 keys = 0.125 bytes/key and meets it 70×
over. Our closest figure is RSS-based and not the same metric — the
coverage-index `BITSET` holds 1,578,994 keys in 27.38 MB index-only peak RSS =
17.3 bytes/key. `J1MU`/`JLMU` give libJudy's exact live-byte accounting in O(1)
and are already surfaced by `memoryUsage()`; that is what should be read before
anyone commits to a bytes/key target, and it is cheap and unrun.

Two proposal items were rejected outright. **64-byte-native geometry is likely
negative on the one axis we win**: `ALLOCSIZES` = {24, 40, 56, 88, 120, 184, 256,
376, 512} bytes lets a leaf be sized to its *population* rather than a hardware
quantum, and replacing that nine-step ladder with {64, 128, 4096} is a coarsening
— `(projected)`, a JudyL LEAF2 at pop = 8 has 80 B payload → 88 B allocation =
11.0 bytes/key, which under a 64/128 quantum rounds to 128 B = 16.0 bytes/key,
**+45%**, concentrated at exactly the small/medium populations where the node
census says most nodes live. A genuinely 64 B linear branch also holds **3** JPs,
not 4, so max fanout drops 7 → 3 and forces earlier bitmap promotion.
**48-bit VA packing is unsafe on targets we ship to**: x86-64 LA57, ARM64 52-bit
VA (`CONFIG_ARM64_VA_BITS_52` ships on real distro kernels), ARM MTE (where the
top byte is a hardware-checked allocation tag and stripping it *faults*), PAC,
and CHERI. The low 3 bits are architecturally guaranteed on every target and
libJudy already uses them at the root JRP. Note also that Miri validates
provenance, **not VA width** — it will happily pass a 48-bit assumption that
segfaults on a 52-bit-VA kernel — and that PEXT/PDEP are microcoded on AMD Zen
1/2 (~18–300 cycles vs 3 on Intel), which the proposal treated as interchangeable
with the other intrinsics.

Two items were adopted from it, unchanged and unconditionally. **Differential
fuzzing against `BTreeMap`/`std::map` as an oracle** — it would have caught #131
in seconds, because "inserted key denied by lookup" is exactly what proptest
finds first. And the **`_Static_assert`s**:
`cJ1_IMMED1_MAXPOP1 * 1 == 15 > sizeof(j_pi_1Index) == 8` was violated silently
for 19 years, and `cJL_IMMED1_MAXPOP1 * 1 == 7 == sizeof(j_pi_LIndex)` holds
today with **zero margin**.

On Rust: the proposal argued it on productivity and safety and never mentioned
the one thing it uniquely delivers — **a clean-room reimplementation is the only
path that escapes LGPL-2.1**, since a fork stays LGPL and so does vendoring.
Against that, **PECL is the binding constraint** (`pecl install judy` today needs
a C compiler and libJudy; requiring `rustc`/`cargo` is a step change in
installability), and a reimplementation inherits 24,849 lines of invariant
surface with none of the field time — #131 and #127 show the original authors got
those invariants wrong in ways that survived 19 years. **A rewrite cannot be
justified by performance on this evidence. It can be justified by licensing,
which is a different argument than the proposal made.** Patent status of the Judy
algorithm was not checked; that is a lawyer question and should be answered
before any clean-room work starts.

### 9.2 What came back, and the mechanisms we did not have

The proposal's author reviewed our measurements, **endorsed
vendor-stock-plus-patches, and withdrew the from-scratch rewrite on the Amdahl
argument.** Three mechanisms they contributed that we did not have:

- **Indirect-branch mispredicts are latency-shadowed.** We declined to claim
  cachegrind's 43–77% figure as a cost because the model is bimodal rather than
  ITTAGE. Their answer is stronger than "the model is wrong": indirect-branch
  resolution on a full miss is ~12–18 cycles, while the dependent child-node load
  it gates is 35–45 cycles from LLC or 150–220 from DRAM. The front end recovers
  long before the line arrives, so the mispredict is real and free. **This retires
  the open question without a PMU run**, because the mechanism makes the answer
  independent of the predictor's accuracy. Explicit recommendation, accepted: do
  not rewrite the dispatch into an unrolled state machine to chase it. Corollary
  they add: inlining node handling still has value, for the different reasons of
  eliminating call/ret overhead and letting the compiler propagate constant
  bitmasks.
- **Packed unaligned key strides defeat the hardware spatial prefetchers.** Branch
  geometry was already solved in 2002; the real target is the **linear leaf
  key-region span in the search path**. A scalar binary search over a 138–510 B
  packed key region hops 3 to 6 distinct 64-byte lines, and because keys are
  packed at unaligned strides (3-byte, for instance), **L1/L2 spatial prefetchers
  do not trigger predictably.** That is the clause we had not articulated, and it
  is what makes the leaf span different in kind from the branch nodes.
- **Align only nodes already >=64 bytes.** Their refinement on memory economics:
  keep the 9-step `ALLOCSIZES` ladder (our +45% worked example stands, and they
  extend it to 60% overhead at a 128 B quantum), and apply 64-byte alignment
  *only* to nodes whose allocated size is already >=64 bytes, leaving sub-64-byte
  nodes packed on 8-byte boundaries. This captures the alignment benefit without
  the coarsening cost, and is better than either of our original positions.

Also confirmed from their side: MLP/AMAC as the correct response to
pointer-chasing latency, with the mechanism named (non-blocking Line Fill Buffers
/ MSHRs servicing 8–16 concurrent misses) and a lane-state-machine sketch
consistent with our prototype; rejection of 48-bit VA packing; preservation of the
16-byte JP because Decode and Pop0 are load-bearing; and all three correctness
defects as upstream bugs justifying a vendored layer.

### 9.3 Two open discrepancies, and one characterisation we corrected

Their restated KPIs are properly disambiguated by type, residency and metric — a
real improvement on the originals. Two carry baselines we cannot reconcile, and
they are **open, not resolved**:

| KPI | their baseline | our position |
| --- | --- | --- |
| L3-resident JudyL point lookup | 18.59 ns → target <=14 ns | ours — agreed |
| DRAM dependent hops | 3.15 → target <=2.2 | ours — agreed, and the right shape for an out-of-cache KPI |
| L3-resident Judy1 point test | **10.2 ns** → target <=7 ns | **we never measured Judy1 point-test latency.** The target is meaningless until that baseline exists. |
| Batched lookup | **18 Mops/s** → target >=45 Mops/s | **does not reconcile.** Ours: serial 20.64 ns = 48.4 Mops/s, 8-lane 12.74 ns = 78.5 Mops/s, both L3-resident. 18 Mops/s implies ~55 ns/op — between our cache-resident and out-of-cache figures, so the operating condition is ambiguous and neither number can be used against the other. |

Their memory targets are now stated per type and to be measured with
`J1MU`/`JLMU` rather than RSS (Judy1 dense <=0.15 B/key, Judy1 sparse <=8.5;
JudyL dense <=9.5 total = 8.0 payload + <=1.5 trie, JudyL random <=14.5). Those
are checkable and we should check them; our only comparable figure is the
RSS-based 17.3 B/key above and is **not the same metric**.

**One characterisation corrected.** Their summary of #131 says the compiler
"emit[s] code that clobbers adjacent struct memory." What we measured is the
opposite failure mode: GCC sees the 8-byte declared destination and **truncates
the copy** — bytes 9–15 are never written, and nothing adjacent is clobbered.
This matters for anyone hunting it, because the symptom is **missing data with an
over-reporting `count()`** (`count=135 walked=71 isset=87` in PR #134), not
corrupted neighbouring fields. A memory-corruption hunt would look in the wrong
place.

## 10. Verdict, and the gates that remained open at decision time

**This verdict has since been executed.** [#142](https://github.com/orieg/php-judy/issues/142)
is the implementation tracker; Stages 0–2 and optimizations O1 and O3 are
merged, and [§11](#11-execution-record-stages-03) is the execution record. The
section below is preserved as written at decision time; where execution revised
a detail — the #131 trigger conditions ([§11.2](#112-stage-1--the-bundled-build-and-the-gate-that-caught-a-flag-pr-146)),
the O3 magnitude ([§11.7](#117-stage-3-o3--the-projection-was-wrong-and-why-pr-150-merged)) —
§11 supersedes it.

**Vendor stock 1.0.5 plus patches. Agreed on both sides of the external review.**
Do not adopt the C modernization as proposed. Do not rewrite in Rust *for
performance*; keep Rust alive as a **licensing** option with a scoped probe if
and only if licensing is stated as a hard requirement, which would reorder
everything here.

Vendor **stock plus Baskins' patch**, not the
[netdata/libjudy](https://github.com/netdata/libjudy) fork. That fork was
evaluated as work item 1 and rejected as a vehicle: exactly two files differ from
the pristine tarball; the header patch is **not netdata's** (its RCS header points
at Doug Baskins' own tree, and applying Debian's
`04_fix_undefined_bahavior_during_aggressive_loop_optimizations.patch` to a
pristine tree yields a byte-identical file); the fork has been abandoned by its
own owner since 2020-10-28, with `./bootstrap` failing on modern autotools and no
release tarballs; netdata themselves have moved to vendoring, and their vendored
subset covers only `JudyCommon` + `JudyL` + `JudyHS`, so it battle-tests neither
the Judy1 path where the patched defect lives nor JudySL at all. The fork also
captures **none** of the modernisation headroom: `cnt` = 0, `prfm` = 0, NEON = 0
in both dylibs, with instruction counts within 0.1%. Drop-in compatibility of the
built artifact is fine for the record (`Judy.h` byte-identical, 192 exported
symbols identical, same `-version-info`), and the licence is unchanged
LGPL-2.1-or-later.

**Endorsed scope, in dependency order** (dependencies and gates, not durations):

1. **Correctness** — `jp_1Index` widening plus the `_Static_assert`s; the
   `SEARCH_LINEAR` guard **and** the `COPYINDEX` defect it masks, never the guard
   alone; `JudyInsArray.c:284`; the `JU_NOINLINE` definitions. The `~0UL` LLP64
   site in `JudySL.c:839` should be checked against the existing Windows CI patch
   set at the same time.
2. **Algorithmic** — popcount-L (`Judy::INT_TO_*` only, per §5.1), and a batched
   `judy_multi_get` AMAC entry point, **gated on extending the prototype's
   coverage from `BRANCH_U*`/`BRANCH_B*`/`LEAF_B1` to LEAF2..LEAF7**.
3. **Process** — differential fuzzing against `std::map`/`BTreeMap` as a CI
   invariant gate, against the vendored C tree.

**Cheap gates that should run before believing any remaining item**, none of
which have: `-DJU_NOINLINE` plus the missing definitions → callgrind on
`j__udySearchLeaf*`, which gates the SIMD leaf-scan item (now with a named
mechanism — prefetcher defeat on packed strides, §9.2 — but still no bound);
insert-phase-gated callgrind, which gates the arena; and `J1MU`/`JLMU` bytes/key
across the 13-corpus set, which gates 64B geometry and settles the memory KPI.
Also unrun: PGO under the round-2 design, the node census against
`leaf32x8`/`br16x16`, and a Judy1 point-test baseline (§9.3).

**What would change this verdict**: a measured SIMD-leaf-scan or
insert-phase-gated arena result clearing ~10%; a bytes/key measurement showing
64B quanta do not regress memory; a php-judy workload where bulk reads dominate;
or **licensing stated as a hard requirement**.

### 10.1 The cost nobody has priced: the build system

Vendoring means adopting Judy 1.0.5's build, and **no estimate of that work
exists**. What is known about it:

1. **Cross-compilation is broken by design.** `src/Judy1/Makefile.am` runs the
   table generator with `$(CC)`, not a host compiler:
   `$(CC) ... -o Judy1TablesGen Judy1TablesGen.c; ./Judy1TablesGen`. Any cross
   build (mingw, aarch64-on-x86, Alpine muslcross) builds a **target** binary and
   then tries to execute it.
2. **Sources are `cp -f`'d** from `JudyCommon/` into `Judy1/` and `JudyL/` with
   no `$(srcdir)` handling. VPATH builds are fragile, and `make clean` deletes
   generated sources that a parallel build then races to recreate. (Relatedly,
   1.0.5 has no working VPATH build at all — `Judy.h` is not found out-of-tree;
   `make -C src` in-tree works.)
3. **`src/obj/Makefile.am` uses a shell glob in `LIBADD`** —
   `libJudy_la_LIBADD = ../JudyCommon/*.lo ../JudyL/*.lo ...`. Automake does not
   expand this; make does, at rule time. **Stale `.lo` files from a previous
   configuration get linked in silently.**
4. **`doc/Makefile` has a parallel-make race** — `make -j4` fails reproducibly on
   `man/man3/JSLG`. The library builds fine; only `doc/` fails.
5. **`configure.ac` injects `-m32`/`-m64` into `CFLAGS`** from
   `AC_CHECK_SIZEOF(void *)`. If php-judy ever drives this `configure`, that
   fights PHP's own ABI flags.

If vendoring proceeds, **this is the bill**: the table generator must become
checked-in pre-generated tables (guarded by `_Static_assert`s) or a
host-compiler split, and the `cp -f` duplication and glob `LIBADD` need rewriting
for `config.m4` / `config.w32`. php-judy today gets libJudy from apt, apk and
Homebrew as a stable packaged dependency; vendoring replaces that with owning the
above across every platform CI builds on, indefinitely. **Budget the decision
against this, not against the C sources.**

## 11. Execution record (Stages 0–3)

Everything above is investigation. This section records what executing the
verdict found — findings of the same kind as §5–§7, in the same voice:
`(measured)` where measured, negatives kept, sub-floor results declined. Every
claim below traces to a merged PR body, an issue comment, or a gate comment on
the [#142](https://github.com/orieg/php-judy/issues/142) tracker. State at the
time of writing: Stages 0–2 and optimizations O1 and O3 are merged; O2/O4/O5
have not landed (O5 has passed its gate measurement, §11.5, but no in-tree
implementation exists yet).

### 11.1 Stage 0 — hotfix, pristine import, licensing (PRs #143, #144)

**Build exploration found release drift before it found anything else.**
`ci.yml`'s Windows job patched libJudy's UL constants with six replacements
before building; the near-duplicate patch block in `release.yml` carried only
five — missing `0xffL → (Word_t)0xff`. On MSVC x64 (LLP64) `0xffL` is a 32-bit
`long`, so `cJU_MASKATSTATE` yields 0 for `State >= 5`, breaking
`JU_SETDIGIT`/cascade for JudySL with long strings: **released Windows DLLs had
been built without a fix that CI builds applied.** One-line hotfix in
[PR #143](https://github.com/orieg/php-judy/pull/143); Stage 1 then deleted
both patch blocks entirely, replacing the download-time regexes with real
source diffs (P5, §11.4).

**The pristine import is provable, not asserted.**
[PR #144](https://github.com/orieg/php-judy/pull/144) imported the 29-file,
24,748-line Judy-1.0.5 subset (sha256 `d2704089…414a63eb`, verified on a fresh
download) as its own commit — so every later patch in the series is a
reviewable diff against it — and verified byte-identity three ways: `cmp` of
every committed blob against the freshly-extracted tarball, a tree-level
`diff -r`, and re-verification of the packaged bytes **inside the built PECL
tarball**. Two import decisions recorded: `JudyPrintJP.c` is live, not dead
(`#include`d by Get/Ins/Del/PrevNextEmpty under trace ifdefs), and
`src/JudyHS/JudyHS.h` is excluded (a standalone compat header whose upstream
include is commented out; nothing references it).

**The licensing review round-trip confirmed the approach — and needed
correcting in three places before its template could propagate.** A
senior-engineering licensing review endorsed the structure: source distribution
satisfies LGPL-2.1 §6 (full source plus build definitions ship via GitHub and
PECL, so relinking is trivially available), `libjudy/PATCHES.md` plus per-file
headers carry the modified-work notices, and the license boundary is the
`libjudy/` subtree. Three of its refinements were adopted. Two of its errors
were corrected on the tracker: the file-change-notice clause is **§2(b), not
§2(a)** (§2(a) requires the modified work itself to be a library; the review's
template cited the wrong clause), and **three of the six rows in its
illustrative patch ledger named wrong files** — P1 lives in
`JudyPrivateBranch.h` (not `Judy1.h`/`JudyPrivate1L.h`), P2 in `JudyPrivate.h`
(the review's `JudySearchLeaf.c` does not exist), O1 in `JudyPrivate.h` (not
`JudyCount.c`/`JudyLTables.c`). Recorded on
[#142](https://github.com/orieg/php-judy/issues/142) so Stage 2's real ledger
was filled from verified paths, not the template.

### 11.2 Stage 1 — the bundled build, and the gate that caught a flag (PR #146)

The build architecture, from
[PR #146](https://github.com/orieg/php-judy/pull/146): pre-generated
`Judy1Tables.c`/`JudyLTables.c`, byte-identical between arm64 (Apple clang 17)
and x86-64 (gcc 13), carrying provenance headers and negative-controlled
compile-time pins; the full **40-wrapper set** enumerated from the per-variant
`Makefile.am`s rather than guessed — `src/obj/Makefile.am` only globs `*.lo`
and would have hidden the three specials: `JudyGet.c` compiles **twice** per
variant (public entry plus a `-DJUDYGETINLINE` internal copy),
`JudyPrevNext[Empty].c` twice (`-DJUDYNEXT`/`-DJUDYPREV`), and `JudyByCount.c`
needs three `NOSMART*` defines. Flag isolation is per-source via
`PHP_ADD_SOURCES_X`, whose flags the PHP build system emits **after** the
global `$(CFLAGS_CLEAN)` on each vendored compile line, so later flags win.

**The headline finding: the gate caught `-funroll-loops`.** The first Linux gcc
bundled build failed exactly one test — `bitset_immed_cascade_integrity_001.phpt`,
the #131 detector shipped by [PR #134](https://github.com/orieg/php-judy/pull/134)
— in two independent full-suite runs. The vendored `-O2` overrides the global
`-O3`, but **`-funroll-loops` has no implicit off switch** and leaked through.
The bisect (gcc 13.4, a minimal C driver of the same cascade shape) then
revised #131's trigger conditions:

| build | detector |
| --- | --- |
| `-O2` | OK |
| **`-O2 -funroll-loops`** | **BROKEN** (walked 71/135) on gcc 13/14 |
| `-O3`, `-O3 -funroll-loops`, full php-src globals | OK on gcc 13/14 |
| `-O3` | **BROKEN on gcc 15** — independently re-confirmed by the differential fuzzer ([PR #145](https://github.com/orieg/php-judy/pull/145)) the same day |

The trigger is compiler-version- and flag-combination-dependent: gcc 13/14
miscompile at `-O2 -funroll-loops` but **not** at bare `-O3`; gcc 15 at `-O3`.
The conclusion, recorded on
[#131](https://github.com/orieg/php-judy/issues/131): **no flag recipe is
trustworthy across compiler versions — only the runtime detector is.** The
bundled tree pins `-O2 -fno-lto -fno-unroll-loops` per source and keeps the
detector in the suite unconditionally.

**A negative control that legitimately did not fail, reported as such.** The
literal "force `-O3` and watch the detector fail" experiment was run — config
level, clean rebuild, compile line verified — and did **not** fail on gcc
13/14, where bare `-O3` happens to generate correct code. That was reported as
an honest caveat rather than manufactured into a pass; the real control was the
genuine hazardous build failing the detector twice before the fix and the full
suite going 220/220 (Linux gcc, macOS clang, Alpine musl) after it.

### 11.3 Stage 4 preparation — the fuzzer, validated to fail (PRs #145, #148)

The differential fuzzer
([PR #145](https://github.com/orieg/php-judy/pull/145): Judy1/JudyL/JudySL/JudyHS
against `std::set`/`std::map`/`std::unordered_map` oracles) was **validated
against both historical bug classes before being trusted**:

- **#131**: against stock sources built at gcc-15 `-O3`, the Judy1 differential
  diverges in the **second smoke cell** — `key 0x3a4 in oracle, J1T says
  absent`, the exact "inserted key denied by lookup" symptom §9.1 predicted a
  fuzzer would find first. Same sources at `-O2`: full 46-cell smoke green.
- **#127**: driving the bulk API against an ASan-built stock library trips the
  `JudyInsArray` off-by-one at `Count == 31` — `global-buffer-overflow … 0
  bytes after 'j__L_LeafWPopToWords'`, the same signature as §6.3.

A 377-cell soak against Homebrew's stock 1.0.5 (classic APIs only) found no
divergence. One repo defect found on the way: the root `.gitignore` ignores
every `Makefile` unanchored (intended for the phpize-generated one at the repo
root), which kept the harness's hand-written Makefile silently untracked —
never shown by `git status`, never staged, absent from #145 without anyone
noticing. Recovered verbatim with a `!Makefile` re-include in
[PR #148](https://github.com/orieg/php-judy/pull/148).

### 11.4 Stage 2 — the correctness series (PR #147)

All seven patches landed — P5 in Stage 1, P1–P4/P6/P7 in
[PR #147](https://github.com/orieg/php-judy/pull/147) — one commit, one
`PATCHES.md` ledger row and one LGPL §2(b) change notice each, with
`git diff <pristine-import> -- libjudy/` equal to exactly the ledger.
[#127](https://github.com/orieg/php-judy/issues/127) and
[#131](https://github.com/orieg/php-judy/issues/131) closed on merge. What the
validation work itself found:

- **P1's definitive proof is a fix table, not a flag rule.** The patched tree
  built with the exact hazardous flag combinations passes the detector on every
  compiler that previously miscompiled it — gcc 15.3 at `-O3`, gcc 14.4 and
  13.4 at `-O2 -funroll-loops`: pre-P1 FAIL (keys lost) → patched **PASS**, all
  three — and gcc's `-Waggressive-loop-optimizations` warning vanishes with the
  widening. **The UB is removed, not avoided**; the conservative per-source
  flags stay pinned as defense in depth.
- **P2's negative control proved the pairing was load-bearing.** The guard fix
  with `COPYINDEX` still hardcoded diverges immediately under the fuzzer —
  exactly the data-loss class §6.2 predicted — while the paired patch is green
  through a 46-cell smoke plus 1,442-cell `-DSEARCH_LINEAR` soak. The control
  also proves the linear path is genuinely selected once the guard is fixed.
- **The `NDEDUG` fix as specified would have been a semantic no-op.**
  Correcting the spelling to `#ifndef NDEBUG` still ends with `NDEBUG` defined
  in every build that lacked it, so the file's asserts stay unreachable either
  way. The applied fix follows the library's own convention instead —
  `#ifndef DEBUG` (`JudyPrivate.h:250`) — which actually makes the asserts
  enableable. And the asserts-live build (`-UNDEBUG -DDEBUG`) fired **no
  dormant assert** across the full suite plus a 50K-key JudySL stress: the
  invariants unchecked for 20 years (§6.5) hold.
- **P4's limit is stated rather than papered over.** The pre-fix out-of-scope
  `pv[]` read is **not ASan-observable** — the compiler reuses a single stack
  slot, which is precisely why the code "works today" — so the evidence is the
  structural fix plus a 200-budget OOM-injection sweep (2..400 step 2, driven
  through the exact `pv[]` recovery window via a `-DTRACEMI` build) with **zero
  consistency failures**, not a sanitizer diff.
- **P3's secondary effect was verified gone by interception, because the
  library's own accounting structurally cannot see it.** Logging `JudyMalloc`
  words shows pre-P3 allocating {5, 11, 23, 47, 63} words at Counts
  {1, 3, 7, 15, 23} and post-P3 {3, 7, 15, 32, 47} (plus 63 at the
  previously-corrupting Count 31) — exactly the size classes `JudyFreeArray`
  frees, so allocation and free agree again. `JudyLMemUsed` cannot show any of
  this: it derives from population, not from the allocation (§6.3).

### 11.5 The O5 gate — AMAC at full leaf coverage (gate measurement on #142; prototype only, nothing landed)

The stated prerequisite for O5 — extend the §5.2 prototype past
`BRANCH_U*`/`BRANCH_B*`/`LEAF_B1` to the full leaf set and prove the win
survives — was run on the benchmark host and **passed**
([#142 gate comment](https://github.com/orieg/php-judy/issues/142)). Speedups
quoted as ×N faster, the §5.2 convention; all `(measured)`, single library
build per config (no per-build replication yet — magnitudes ±10% until the
in-tree round):

- **Full coverage, zero fallbacks — and the win grew.** The extended prototype
  mirrors the complete JudyL GET dispatch **including the DCD narrow-pointer
  checks the prior prototype omitted**; the `default:` fallback counter reads 0
  across all 115 correctness gates and all timed runs. `wsparse` — previously
  100% fallback, its ×1.23 unusable (§5.2) — now measures **×2.02**
  [1.99, 2.05]; out-of-cache `wdense` (n = 4×10^7, the bulk-op regime) reaches
  **×3.28** [3.21, 3.43] (10.3 → 33.7 Mops/s). The DCD checks cost ~5–7% of the
  prior speedup on the already-covered corpora — their absence had slightly
  flattered §5.2's ×1.62–1.79, which is recorded here as the honest correction.
- **Phasing is load-bearing, discovered by ablation.** Giving BRANCH_B its own
  prefetch epoch *collapsed* `wbase16` to ×0.65 with negative lane scaling;
  resolving small branch structs on demand and spending epochs only on lines
  worth waiting for restored ×1.97. Spec for the in-tree implementation:
  **branches one-phase, leaves two-phase.**
- **The lane optimum moved from 4–8 to 16–32** once leaf search entered the
  work phase — an in-tree `judy_l_multi_get` should default to ~16 lanes.
- **Tiny fully-cached trees regress** (×0.86–0.95; nothing to overlap, lane
  overhead only), so O5 needs a serial fallback below a small population
  threshold.

### 11.6 Stage 3, O1 — popcount, independently replicated and merged (PR #149)

[PR #149](https://github.com/orieg/php-judy/pull/149) landed the
`j__udyCountBits{B,L}` hardware-popcount arms at the existing HP-UX/Itanium
seam and re-ran the §5.1 A/B against the vendored, fully-patched tree under the
round-2 protocol (three arms including a flag-only control, 5 randomized-link
builds per arm, per-build medians, percentile-bootstrap CIs). Ratios below are
time treatment/base — below 1.0 is faster; all `(measured)`:

- **Near-exact independent replication.** `wbase16` get **×0.8413** against the
  prior round's ×0.8414; `wdense` ×0.8249 against ×0.8290 — different tree
  (P1–P7 applied), different builds, same protocol, same answer.
- **The control calibrated a claim floor, and sub-floor cells were declined.**
  All 45 flag-only-control objects are **byte-identical** to base, yet 2 of its
  18 cells came back nominally non-null — so those excursions are residual
  layout/measurement noise slipping past the 5-build randomization, and they
  set the claim floor at **~1.3%**. Consequence drawn: `wclust` get (−2.4%,
  same cell as a −1.25% control excursion) and `urand16` (−0.7%) are **not
  claimed**; every claimed cell is 6–70× above the floor with a null same-cell
  control.
- **The arm64 arm is new.** `__builtin_popcount{,ll}` lowers to base-ISA
  `cnt`/`addv` at any optimization level, so aarch64 gets the arm
  unconditionally (§5.1's numbers were x86-only); x86-64 stays gated on
  `__POPCNT__` via the configure probe, so flagless builds compile
  byte-identical stock SWAR (verified: defeated-probe objects contain 0 popcnt);
  MSVC x64 uses `__popcnt64`, hardware POPCNT assumed and documented.
- **The P7 `JudyNoInline.c` copies mirror the fast arms**, so a `-DJU_NOINLINE`
  profiling build measures the algorithm production builds actually run.

> **Amendment (2026-08-18, §11.10)** — two corrections to this section's
> floor language. (1) The ~1.3% floor generalized from one round's 18
> control cells; pooled controls across all four Stage 3 rounds (~97 cells)
> put the honest floors at **~3% L3-resident / ~1.3% out-of-cache**. Every
> O1 *get* claim clears even the revised floor; `wbase16` insert ×0.9691 is
> downgraded to *directionally consistent, weakly bounded* (a ~3% point
> against a ~3% floor). (2) "Residual layout/measurement noise" mislabels
> the control arm: a flag-only or comment-only ctl built with the same link
> seed is byte-identical to base, so it is a **run-order/temporal control**,
> not a code-layout control — the layout control is the 5 randomized link
> orders within each arm. The floor's provenance therefore rests on the
> pooled-control record in §11.10, not on a "layout noise" reading of this
> one arm.

Full tables with CIs: [BENCHMARK.md](../../BENCHMARK.md), "Bundled libJudy
optimizations (measured)".

### 11.7 Stage 3, O3 — the projection was wrong, and why (PR #150, merged)

§7.6 priced the word-access `JU_JPDCDPOP0`/`JU_JPSETADT` rewrite from
instruction counts, and the tracker projected low-single-digit get /
mid-single-digit insert gains. Measured
([PR #150](https://github.com/orieg/php-judy/pull/150), same protocol as O1
with a comment-only control, all arms carrying O1): get `wdense` **×0.6939**
[0.6796, 0.7014] cache-resident — speedup CI-low **1.426** — and **×0.6944**
[0.6931, 0.6971] out-of-cache (n = 4×10^7, CI-low 1.434); `wbase16` get
×0.8132; insert ×0.834–0.881 across the four word corpora. All 36 control cells
null (the control objects came out byte-identical to base). `urand16` (JudySL,
string keys) sits at ≤1.2%, below the ~1.3% floor, and is **not claimed**. Code
size falls too: `JudyLGet.o` `.text` **−27.6%**, whole library **−14.9%**
(gcc 11.4 x86-64).

**The mechanistically important part: the win survives out-of-cache fully
intact** — ×0.694 at both residencies — where O1's popcount gain shrank from
~17% cache-resident to ~7% out of cache. The difference is the mechanism:
popcount shortens a compute chain that Amdahl dilutes once DRAM round trips
dominate, while O3 collapses **5 dependent byte loads into 1 word load per
descend level**, shortening the serial load-to-use chain itself — the resource
§4's MLP measurement identified as the constraint, and the same bottleneck AMAC
(§5.2, §11.5) attacks from the other end by overlapping whole descends.

**Why the projection missed**: it was priced as instruction-count savings under
the Amdahl frame — even though §7.6's own text said "the instruction count is
not the point" — and dependency-chain shortening compounds per level and does
not dilute out of cache. It is the mirror image of §4: round 1 over-claimed
from a model, this projection under-claimed from one, and both were settled the
same way — by measuring.

> **Mechanism caveat (2026-08-18, synthetic internal panel — §11.10).** The
> O3 *measurement* is the most robust in this program — the worst-case
> build-pairing rank bounds still put `wdense` get at 1.418–1.434× — and
> every cell survives the revised claim floor. The *mechanism narrative*
> above overstates its transfer, in three ways. First, the "5 dependent byte
> loads → 1" framing suggests a pointer chase; in fact the DCD bytes share
> the jp's cache line with `jp_Addr`, so the byte loads are parallel L1 hits,
> not a chase. Second, chain arithmetic prices only ~3–5 ns of the ~7 ns
> L3-resident win — the remainder is consistent with front-end/OoO-window
> relief, not chain shortening alone. Third, the ~29.7 ns (~150-cycle)
> out-of-cache saving requires cross-lookup memory-level parallelism, which
> the harness's independent-key loop maximizes; serially-dependent or
> PHP-interleaved consumers will see less. **No PMU evidence exists for any
> mechanism claim in this program** (the bench host has none, §3) — read
> every mechanism sentence in §11.6–§11.7 as hypothesis consistent with the
> sign, not as measurement. The same applies in kind to O1: chain arithmetic
> explains roughly half its win; what makes O1 solid is the cross-round
> independent replication, not the mechanism story.

### 11.8 What the execution phase taught

Transferable lessons, one instance each:

- **A gate you never watched fail is not a gate.** Stage 1's flag isolation was
  proven by the detector failing on the leaked `-funroll-loops` build (§11.2);
  the fuzzer was validated to fail against both historical bug classes before
  being trusted (§11.3); P2 shipped with a negative control that diverges
  (§11.4).
- **Controls calibrate floors, and sub-floor results are declined.** O1's
  byte-identical-code control set a ~1.3% claim floor and `wclust`/`urand16`
  were not claimed (§11.6); O3 declined `urand16` against the same floor
  (§11.7). The floor itself was later revised — one round's calibration does
  not generalize; pooled controls put the L3-resident floor at ~3% (§11.10).
- **A projection built on the wrong mechanism can be an order of magnitude off
  in either direction.** Round 1 over-claimed stall time from an MLP = 1 model
  and was retracted (§4); §7.6's instruction-count frame under-priced O3, which
  measured ×0.694 where "low single digits" was projected (§11.7).
- **Provenance discipline caught three errors before they entered the record**:
  negative results that constrain the decision as much as the positives did but
  had never been filed — caught and filed at
  [#113 (negative results)](https://github.com/orieg/php-judy/issues/113#issuecomment-5324250328);
  the two colliding 91s of §7.1, whose conflation inverts the conclusion; and a
  dangling cross-reference — an earlier #127 comment pointed at string-layer
  findings that existed nowhere until
  [#113 (string-layer performance)](https://github.com/orieg/php-judy/issues/113#issuecomment-5324314505)
  filed them. Each was caught by requiring every written claim to trace to a
  durable source.
- **A semantic no-op can hide inside an "obvious" typo fix.** The literal
  `NDEDUG → NDEBUG` correction changes nothing; only following the library's
  actual `#ifndef DEBUG` convention makes the asserts live — and running them
  was what showed the 20-year invariants hold (§11.4).

---

### 11.9 Stage 3, O5 — the in-tree batched lookup: implemented, measured, DROPPED

O5 closed the Stage 3 list the way O2 did — killed by its own gate — but
at a different layer: the C-level entry point PASSED the gate the §11.5
corpora define, and the php-judy adoption failed BOTH its PHP-level gate
and a corpus-coverage hole the prototype corpora had hidden. Nothing
landed; the complete implementation is archived on branch
`vendor/stage3-o5-amac` (JudyLMultiGet TU + public header + build wiring
+ diffuzz `MULTIGET=1` oracle mode watched-to-fail on two deliberately
broken builds + a 300k-key adversarial `.phpt`; 223/223 on macOS
bundled/Debian/Alpine/system-lib at the time it was cut). All
`(measured)`, honeycomb x86-64 gcc 11.4 `-O2 -mpopcnt`; the C gate is
4 arms (pre / byte-identical comment-only ctl / post / thresholds-off
post0) × 5 randomized-link builds, per-build medians,
percentile-bootstrap CIs; artifacts in `/var/tmp/jp113/o5/` and the
session scratchpad.

- **The C-level gate passed everywhere the §11.5 corpus family looks.**
  Against the current serial baseline (O1+O3 active — ~30% faster than
  the popcount-only build the §11.5 prototype was measured on), 16-lane
  batched lookup cleared CI-low > 1.0 on all nine L3 corpora at n=10^6:
  ×1.51 (`wdense`) to ×2.17 (`wsparse`); out-of-cache n=4×10^7: ×2.70
  [2.65, 2.76] (`wdense`), ×2.91 [2.89, 2.93] (`wsparse`). Controls
  null; post-serial null-checks null (the TU is additive); `JLMU`
  byte-identical across all four arms on every corpus.
- **The tiny-tree threshold was derived, and an 8192 guess would have
  shipped a ×2.2 regression.** The crossover sweep (pop 1024–262144)
  put cache-resident trees at pop 16K–64K at ×0.46–0.98 through the
  lanes (worst: `wclust` at 16384, ×0.46); 262144 was the smallest
  measured population where every swept shape cleared CI-low > 1.0.
- **The kill: batched descend loses on heterogeneous batches, and the
  gate corpora could not see it.** Every §11.5/§5.2 corpus is unimodal
  in descend shape. A tree holding BOTH a dense region and sparse
  random keys (the shape any real merged workload has) measured, at
  pure C level, zero fallbacks, on the same build that wins above:
  probe both halves ×0.74 (52.1 vs 38.7 ns/op), while probing EITHER
  half alone still wins (dense-only ×1.27, sparse-only ×1.76 — same
  tree, same build). Lane count 4/8/32 does not rescue the mixed case
  (×0.71–0.78). The limitation is BATCH-COMPOSITION heterogeneity, not
  tree structure: when the 16 in-flight lanes hold keys of divergent
  descend classes the machine loses; when they agree it wins.
  Mechanism unverified (no PMU on the bench host) — the hypothesis
  consistent with the sign is that the per-step type-dispatch branch
  is predictable exactly when lanes agree.
- **The PHP-level adoption gate then failed at every call site.**
  A/B (same-tree pre/post `judy.so`, docker pinned, 7 interleaved
  trials, process-run replication — disclosed as weaker than the C
  gate's build replication): set operations and `equals` probe in
  JLN-ascending order, which hands serial descend near-perfect
  locality — `intersect` ×0.96, `diff` ×0.97, `xor` ×0.93, `equals`
  ×0.81 at n=10^6, and still ×0.94–1.00 at n=8×10^6 out-of-cache, the
  regime the bulk-op case was supposed to own. `getAll()` (genuinely
  random user batches) tracked the heterogeneity finding exactly:
  ×0.72–0.77 on mixed-shape trees, winning only when the caller's
  batch happens to be shape-homogeneous (probe-grade +10% sparse-only)
  — which the extension cannot detect in advance. Prefetching the
  resolved value lines before emission did not move it. House rule
  applied: no shipped default may carry a measured plausible-workload
  regression → every adoption site dropped; with zero consumers the
  vendored TU would be dead code → not merged either.
- **What §5.2's projection got wrong, recorded:** its bulk-op candidates
  were `mergeWith()`/`union()`/`keys()`/`toArray()`. The first two now
  route every key through the write-dimension helper (re-entrant user
  code mid-loop; the redundant lookup §5.2 targeted was separately
  removed by the 2.5.2 cursor-reuse change), the last two are
  JLN-dependent walks — none is batchable. The sites that ARE batchable
  probe in sorted order or with uncontrolled batch composition, which
  is precisely where AMAC does not pay. The ×1.5–2.9 C-level wins are
  real and reproducible — they need a caller that issues large,
  shape-homogeneous, randomly-ordered key batches, and php-judy does
  not have one.

Reopening condition: a batched entry point becomes worth revisiting if
(a) a lane scheduler that tolerates heterogeneous batches is designed
and wins on the mixed corpora above, or (b) a PHP-facing bulk API with
documented workload constraints is explicitly requested on the tracker.

> **Adversarial re-review (2026-08-18, synthetic internal panel — §11.10):
> verdict AMEND — the drop is reopened behind a partition-gate experiment.**
> This addendum is deliberately short; the partition-gate work (in progress
> on a separate branch) will write the full follow-up. Key facts, each
> re-derived from raw artifacts (honeycomb `/var/tmp/jp113/o5rev/`, incl.
> the reviewer's persisted `php3-bench.csv`):
>
> - **The drop's PHP matrix never ran `getAll()` out-of-cache.** The
>   archived implementation, unmodified, measures **×1.534** (sparse,
>   n=8×10^6) and **×1.094** (mixed, n=8×10^6) there; the same run also
>   replicated the drop's unpersisted "+10% sparse" L3 cell at ×1.085.
> - **The heterogeneous-batch loss is an ordering effect, not intrinsic.**
>   A counting partition of the batch by descend class (~1.1 ns/key) flips
>   10–75% dense mixes to **×1.28–1.65**; a qsort partition fails
>   (~34 ns/key overwhelms the win).
> - **The C serial baseline was handicapped**: it computed probe keys in a
>   dependent chain *inside* the timed loop, inflating the array-fed
>   headline ratios ~25–30% (the sparse headline corrects to ~×1.58).
> - **Provenance gap**: the "+10% sparse" observation above had no
>   persisted artifact at drop time (now replicated and persisted, see
>   first bullet).
>
> Upheld unchanged: the set-ops/`equals` kills, the `mergeWith` re-entrancy
> constraint, and the tiny-tree threshold evidence. Status: **reopened,
> gated** on the partition experiment; nothing in the vendored tree changes
> until that gate reports.
>
> **Follow-up in progress: §11.11** records the reopen's measurements. Two
> corrections to this addendum's own expectations already stand there: the
> tiny-tree threshold evidence is NOT upheld once the serial baseline is
> corrected (the shipped `cJL_MULTIGET_SERIAL_POP1` = 262144 was derived
> against the handicapped arm, and `wdense` loses at n=10^6 while winning
> at n=8×10^6), and the corrected baseline shows a substantial part of
> §11.9's own ×1.51–×2.17 C-level headline was baseline artifact.

### 11.10 The Stage 3 adversarial re-review — the claim floor revised, and what moved

On 2026-08-18 the Stage 3 results were put through an internal adversarial
review: **three parallel reviewers re-derived every headline number from the
raw artifacts** (the gate CSVs and build trees on honeycomb —
`/var/tmp/jp113/`, including the new `o2/icc/` and `o5rev/` runs — plus the
#142 gate comments and PR records). Stated first, per the standing §8
discipline: **this was a synthetic panel — adversarial brainstorming and
self-bias detection, not external peer review.** Its convergence is signal,
not external evidence; an external re-run remains the outstanding stronger
check on everything below. Verdicts: **O2 drop UPHELD** (two record errors
corrected, §7.6), **O5 drop AMENDED** (reopened behind a partition gate,
§11.9), **merged claims mostly SOLID** with the specific downgrades recorded
here.

**The "~1.3% claim floor" is refuted as a standing constant.** It was
calibrated once (§11.6, from 18 control cells in one round) and then reused
as if universal. Pooled controls across the four Stage 3 rounds — ~97
control cells, every one from an arm whose binaries were byte-identical to
base or differed only via `__LINE__` constants — show: worst single control
excursion **+2.97%**, and one control cell with a **CI excluding 1.0 at
2.59%** (both O4 round, L3-resident). The honest floors are per-residency:

- **~3% for L3-resident cells** — small-n L3 timing carries build/run noise
  the 5-build randomization does not fully absorb;
- **~1.3% for out-of-cache cells** — where the original figure does hold.

**Consequences, applied to the record** (details at the cited sections):

| item | was | now |
| --- | --- | --- |
| O4b `urand16` overwrite ×1.0188 | claimed | **not claimed** — inside the L3 noise envelope (§7.7) |
| O4b `varlen` overwrite ×1.0145 | claimed (marginal) | **not claimed** — same basis (§7.7) |
| O4a `urand16` iterate ×1.0501 | claimed | **artifact-risk** — worst-case build pairing reverses to ×0.9863; 6.1% pre-arm build spread (§7.7) |
| O4a struct16 L3 iterate/riter family ×1.029–1.036 | claimed | **artifact-risk** — same corpus family carried +2.4–3.0% control excursions in the same run (§7.7) |
| O4 control disclosure | 2 contaminated cells disclosed | **6** L3 control cells had ≥2% excursions — under-disclosure corrected (§7.7) |
| O1 `wbase16` insert ×0.9691 | claimed | **directionally consistent, weakly bounded** — ~3% point vs ~3% floor (§11.6) |
| the byte-identical ctl arms | described as code-layout controls | **run-order/temporal controls** — the layout control is the 5 link seeds per arm (§7.6, §11.6) |

**Everything else survives the revised floor**: all O3 cells (worst-case
build-pairing rank bounds still 1.418–1.434 on `wdense` get), the O1 get
claims and `wdense` insert, O4d, the O4b struct cells, and O4a `struct32`.
The merged patches all keep at least one clean claiming cell; what changed
is which cells the record may cite.

**Mechanism claims are hypotheses.** No PMU exists on the bench host, so no
mechanism narrative in §11.6–§11.7 (or §11.9) is a measurement; the O3
mechanism caveat in §11.7 records specifically how far the "shortens the
serial chain" story overstates transfer.

**Release-run corroboration is bounded.** Decomposing the GHA bench-compare
run 32189100948 shows string-keyed ops improved as much as int-keyed
(−9.3% vs −8.8%) where O1+O3 predict ~0 on string keys; the baseline was a
foreign-provenance distro binary; and the tool self-stamped the run
CONTAMINATED. That run therefore corroborates only this much: **the shipped
bundle is not slower than the previous release, and int reads moved in the
right direction.** It is not per-optimization evidence, and must not be
cited as such.

### 11.11 Stage 3, O5 reopen — the partition measured, and a bench-host collision

The §11.9 drop was AMENDED by the §11.10 re-review and reopened behind a
partition gate. This section records the reopen **in progress**: the
partition's own gate is measured and clean, the adoption's decisive
PHP-level cells are **not yet measured**, and no verdict is claimed here.
Nothing has landed. Provenance as always: honeycomb x86-64 gcc 11.4,
`-O2 -mpopcnt`, arms `pre` (main, no batched entry point) / `ctl` (the
archived unpartitioned `vendor/stage3-o5-amac` implementation) / `post`
(partitioned) / `post0` (thresholds compiled out), **5 randomized-link
builds per arm** with the build as the replication unit, interleaved
trials, per-build medians, percentile-bootstrap CIs, `taskset -c 2`.
Harness committed at `research/libjudy-modernization/o5p-harness/`.

**The corrected serial baseline.** The §11.10 review found the original
gate's serial arm handicapped: it computed each probe key in a dependent
chain *inside* the timed loop. The reopen's bench pregenerates the probe
stream and reports **both** baselines, so the record can be compared
against the old numbers. The correction is large — the old-style column
below runs ×1.4–×4.5 where the corrected column runs ×0.7–×1.6 — and it
retroactively explains the §11.9 headline: **a substantial part of the
original ×1.51–×2.17 was baseline artifact, not batching.**

**Gate 1 — the partition does what the review predicted.** Against the
archived implementation on heterogeneous batches (4096-key calls):

| corpus | n | pre serial (ns/op) | post mg4096 vs pre (corrected) | vs pre (old-style) | post vs ctl (partition effect) | ctl vs pre (archived) | verdict |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `wmix10` | 1,000,000 | 31.98 | **x1.284 [1.264,1.302]** | x2.04 [2.00,2.07] | x1.521 [1.493,1.542] WIN | x0.84 [0.84,0.85] REG | WIN |
| `wmix25` | 1,000,000 | 29.49 | **x1.240 [1.214,1.271]** | x1.97 [1.93,2.02] | x1.831 [1.780,1.876] WIN | x0.68 [0.67,0.69] REG | WIN |
| `wmix32_50` | 1,000,000 | 20.19 | **x1.086 [1.053,1.133]** | x1.69 [1.64,1.77] | x2.444 [2.366,2.549] WIN | x0.44 [0.44,0.45] REG | WIN |
| `wmix32_90` | 1,000,000 | 10.53 | **x0.762 [0.724,0.794]** | x1.40 [1.33,1.46] | x2.442 [2.322,2.545] WIN | x0.31 [0.31,0.31] REG | REG |
| `wmix50` | 1,000,000 | 25.69 | **x1.254 [1.206,1.298]** | x1.89 [1.81,1.95] | x2.387 [2.297,2.470] WIN | x0.53 [0.52,0.53] REG | WIN |
| `wmix75` | 1,000,000 | 17.76 | **x1.060 [1.002,1.107]** | x1.65 [1.57,1.72] | x2.902 [2.746,3.032] WIN | x0.37 [0.35,0.37] REG | WIN |
| `wmix90` | 1,000,000 | 11.92 | **x0.774 [0.725,0.800]** | x1.37 [1.28,1.41] | x2.547 [2.385,2.632] WIN | x0.30 [0.30,0.31] REG | REG |
| `wmixc50` | 1,000,000 | 28.03 | **x0.659 [0.630,0.662]** | x0.99 [0.94,0.99] | x1.250 [1.193,1.271] WIN | x0.53 [0.52,0.53] REG | REG |
| `wmixs50` | 1,000,000 | 28.07 | **x1.335 [1.296,1.373]** | x2.11 [2.04,2.16] | x2.341 [2.259,2.407] WIN | x0.57 [0.56,0.58] REG | WIN |

Every heterogeneous cell improves against the archived implementation,
×1.25 to ×2.90, which is the reopen's central claim. The review's
prototype prediction for the 90%-dense cell replicates closely (predicted
×0.77, measured ×0.774).

**Gate 2 — and it costs nothing on unimodal batches.** The
no-regression criterion is the `post vs ctl` column:

| corpus | n | pre serial (ns/op) | post mg4096 vs pre (corrected) | vs pre (old-style) | post vs ctl (partition effect) | ctl vs pre (archived) | verdict |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `wbase16` | 1,048,576 | 19.81 | **x1.422 [1.395,1.513]** | x2.14 [2.10,2.27] | x0.997 [0.935,1.056] null | x1.43 [1.40,1.52] WIN | WIN |
| `wbase64` | 1,000,000 | 9.80 | **x1.038 [1.015,1.125]** | x1.95 [1.91,2.11] | x0.991 [0.917,1.075] null | x1.05 [1.02,1.13] WIN | WIN |
| `wclust` | 1,000,000 | 31.21 | **x1.593 [1.589,1.635]** | x2.15 [2.14,2.20] | x0.999 [0.977,1.025] null | x1.60 [1.59,1.63] WIN | WIN |
| `wdense` | 1,000,000 | 6.93 | **x0.902 [0.891,0.959]** | x1.85 [1.84,1.97] | x0.985 [0.925,1.054] null | x0.92 [0.90,0.97] REG | REG |
| `wimm3` | 1,000,000 | 19.09 | **x1.349 [1.344,1.377]** | x1.86 [1.84,1.90] | x1.001 [0.985,1.017] null | x1.35 [1.34,1.38] WIN | WIN |
| `wleaf1` | 1,000,000 | 22.14 | **x1.414 [1.406,1.451]** | x2.03 [2.02,2.06] | x0.994 [0.975,1.012] null | x1.42 [1.42,1.46] WIN | WIN |
| `wpair` | 1,000,000 | 15.59 | **x1.186 [1.179,1.222]** | x1.73 [1.72,1.79] | x0.993 [0.967,1.023] null | x1.19 [1.18,1.23] WIN | WIN |
| `wrand40` | 1,000,000 | 29.15 | **x1.422 [1.417,1.451]** | x2.15 [2.14,2.19] | x0.986 [0.968,1.006] null | x1.44 [1.44,1.47] WIN | WIN |
| `wsparse` | 1,000,000 | 34.21 | **x1.570 [1.561,1.598]** | x2.51 [2.46,2.53] | x0.990 [0.979,0.999] REG | x1.59 [1.58,1.61] WIN | WIN |

Null on eight of nine; `wsparse` −1.0% [−2.1%, −0.1%], inside both the
~3% L3-resident and ~1.3% out-of-cache claim floors of §11.10. Controls
and post-serial null checks are null throughout; `JLMU` was byte-identical
across all four arms on all 13 corpora.

**What the corrected baseline exposes, and it is not the partition's
fault.** Four cells lose to plain serial: `wdense` ×0.902, `wmix90`
×0.774, `wmix32_90` ×0.762, `wmixc50` ×0.659. On `wdense` the *archived*
arm loses identically (×0.92), so this is the batched machine on
cache-resident cheap-descend trees, newly visible only because the
baseline stopped being handicapped. The mechanism is consistent with the
sign — a tree that fits cache has no misses to overlap, so the lanes add
overhead and buy nothing — but the host has no PMU, so that remains a
hypothesis, per the standing §11.7/§11.10 caveat.

**The shipped tiny-tree threshold is wrong under the corrected baseline.**
`cJL_MULTIGET_SERIAL_POP1` = 262144 was derived against the handicapped
arm. Measured now, `wdense` **loses ×0.902 at n=10^6 and wins ×1.50 at
n=8×10^6** — a residency crossover far above the shipped cutoff. A
population threshold is also the wrong *axis*: at the same n=10^6,
`wsparse` wins ×1.57 while `wdense` loses. A JLMU (tree-bytes) threshold
was considered and **rejected on measurement**: `wmixc50` loses at 15.5 MB
while `wsparse` wins at 16.9 MB, so any byte cutoff separating them would
be fitted to two points. Threshold re-derivation is an open item of this
reopen, not a settled result.

**Bench-host collision — disclosed, with the discarded data named.**
Partway through this matrix a second php-judy benchmark campaign
(`bench-threearm.php`, system-vs-bundled, in docker) started on the same
host. The two corrupted each other. Both individually satisfied this
project's `loadavg < N/2` hygiene rule — 24 cores, loadavg peaked at
2.87 — and **that rule is insufficient**: two memory-bound benchmarks
contend for LLC and memory bandwidth regardless of core pinning. This is
a real limitation of a heuristic the project has leaned on since the O1
round.

- **Usable, and reported above:** the L3 sweep (loadavg 0.56–1.04) and the
  heterogeneous-mix sweep (0.96–1.06). The mix sweep carried one
  single-trial excursion (`wmixs50`, +20% in the `pre` baseline at trial
  3); it is reported with that trial excluded, which moves the cell
  ×1.338 → ×1.335 and changes no verdict.
- **Discarded, to be re-run in full:** the out-of-cache sweep — valid for
  trials 1–2 only (`wsparse` n=8×10^6 `pre`/serial reads 69.5 ns/op in
  trials 1–2 and 156.6 ns/op in trial 3, a 2.2× shift in an *untouched
  baseline*). The `wdense` ×1.50 and `wsparse` ×1.51 figures quoted above
  come from the clean trials and are **provisional pending that re-run.**
- **Never reached:** the crossover sweep, the PHP-level A/B, the
  short-batch probe, and the residency sweep.

Two process changes came out of it, both committed with the harness:
`o5p-stability.py`, which fails any cell whose untouched `pre` baseline
drifts across trials (it flags the collided sweep at 116–142% and clears
the L3 sweep at ≤3.7%), and a `/var/tmp/BENCH_LOCK` mutex every driver now
takes. The collision was originally caught by luck — a partial read
disagreeing with a full read — which is not a control.

**Status: OPEN.** The partition is validated at C level; the adoption is
not yet judged. The decisive cells are PHP-level `getAll()` on a mixed
tree at n=10^6 (the cell that killed §11.9) and sparse at n=8×10^6, plus
the two adversarial shapes added for this round (pure-dense and
clustered), which are the adoption's worst measured C-level regimes. If
those regress, the honest outcome is that the partition is a real result
and O5 nevertheless stays dropped for want of a shippable consumer —
the same reasoning as §11.9.

## Limits of this record

Stated plainly, because they bound everything above.

- **One host for timing, one compiler family.** An idle 24-core i9-12900F with
  gcc 11.4.0 for every timed arm, x86-64 only. Source review, codegen and
  sanitizer work used arm64 Darwin with gcc-15.2.0 and Apple clang 21.
- **No PMU, ever.** Every cache and stall figure is cachegrind simulation and
  every derived stall time is a bound, not a measurement.
- **The write-path code-size findings had no timing when first recorded** (§7.6;
  both have since been timed under [#142](https://github.com/orieg/php-judy/issues/142):
  item 1 shipped as O3 with measured wins, item 2 was dropped as O2 — see the
  outcome note in §7.6), and the
  strict-aliasing null is Judy1-only on one compiler and one target (§7.3).
- **The 32-bit path was syntax-checked, never built or run** (§7.4).
- **The expert panels are synthetic** and are not peer review (§8); the panel run
  was partial.
- **No independent verification** of most of this. The external review round (§9)
  is the closest thing to it, and it is one reviewer working from our reported
  numbers rather than re-running them, with two of their own baselines still
  unreconciled against ours (§9.3). The 2026-08-18 adversarial re-review
  (§11.10) re-derived the Stage 3 numbers from raw artifacts, but it is a
  synthetic internal panel and does not count as independent verification
  either.

## Reproduction

The C harnesses for the popcount A/B, the corpus generator, the MLP prototype and
the OOM injectors are **not committed here** — unlike `shm-arena/` and
`iteration-cost/`, they were built in throwaway trees. The patches are described
in [#113](https://github.com/orieg/php-judy/issues/113) (`popcnt.patch`,
`JudyNoInline.c`, `searchlinear.patch` — the last of which **must not land without
the `COPYINDEX` fix**, §6.2). Committing the harnesses is a precondition for
anyone re-deriving §5, and is itself an open item.

The one thing in this investigation that **is** committed and re-runnable ships
with the extension: `tests/bitset_immed_cascade_integrity_001.phpt`, which fails
on a miscompiled system libJudy.

Execution improved this position: the differential fuzzer is committed under
[`research/differential-fuzz/`](../differential-fuzz/) ([PR #145](https://github.com/orieg/php-judy/pull/145),
[#148](https://github.com/orieg/php-judy/pull/148)), and the O1/O3 measurement
protocol runs against the vendored tree in this repository (raw CSVs, drivers
and build trees for those rounds remain on the benchmark host, per the PR
records). The §5 investigation-round harnesses remain uncommitted.
