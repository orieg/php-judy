# Pre-registration — B-vs-C decomposition (three-arm benchmark)

**Status**: frozen point-in-time record, written BEFORE the attribution arm was
measured. One-per-gate. Do not edit the prediction after the fact; record the
outcome in the "Result" section at the bottom instead.

**Written**: 2026-08-19, after phase 1 (cache-resident + memory) and phase 2
(C-vs-C control) completed and were read, and after phases 3 and 4 **failed**
(`PHASE3 rc=1`, `PHASE4 rc=2`) producing no attribution JSON. No pristine-static
(arm S) result had been produced or observed at the time of writing. Verifiable
by the git commit timestamp of this file relative to the commit adding
`run5-attribution.json`.

## What is being decomposed

Phase 1 measured, claim-grade on an exclusive pinned host (Linux x86-64,
gcc 14.2.0, PHP 8.4.24, control `+0.00% [-0.12, +0.17]` over 43 rows):

| family | n | median B-vs-C | range |
| --- | --- | --- | --- |
| int/bitset-keyed | 33 | **-17.66%** | -30.54 .. -5.07 |
| string-keyed | 57 | **-22.16%** | -45.38 .. -3.65 |

90 cells FASTER, 0 SLOWER, 4 null.

B-vs-C bundles five differences at once. Arm **S** (pristine Judy-1.0.5, sha256
`d2704089…` verified identical to the official tarball, compiled static into the
extension with the *identical* pinned vendor CFLAGS, verified POPCNT=0 /
BSWAP=12) splits them:

- **S vs C** = our source patches ONLY (P1-P7, O1, O3, O4), linkage and flags held constant.
- **B vs S** = shared-library linkage + Debian hardening flags + Debian patch 04.

## The coordinator's prediction (recorded verbatim in substance)

S-vs-C will be **small — plausibly single-digit and concentrated in int-keyed
cells** — while B-vs-S carries the bulk of the -17.7%/-22.2%. Rationale: O1 has
zero popcount duty cycle on string corpora and O3 measured <=1.2% on
string-shaped keys, so our source optimizations predict roughly null on string
paths; a uniform uplift that is flat-or-larger where the optimizations cannot
act is the fingerprint of a build/linkage effect (the same signature the
adversarial panel used to demolish the GHA "corroboration", FINDINGS §11.10).

## My prediction — I expect this to be substantially WRONG, and here is why

I agree completely with the *discipline* (decompose before publishing) and with
the *risk* (a key-type-agnostic uplift is the classic linkage fingerprint). I do
**not** agree with the specific quantitative prediction, and I am recording my
disagreement now so that it is falsifiable rather than retrofitted.

I predict **S-vs-C will be large and will carry most of the string gains**, for
two reasons the "null on strings" argument does not cover:

1. **O4 is itself a string-layer optimization, and the biggest phase-1 cells are
   exactly its targets.** O4a deletes a full `strlen` pass per `JudySLNext` —
   i.e. per element of an ordered string walk. O4d deletes an entire
   `JUDYHASHSTR` pass plus two JudyL descents per `JudyHSDel`. The largest
   phase-1 movers are `core.string_to_int.free` (-43.7%),
   `core.string_to_*.iter` (-32 .. -36%) and `api.range.size.string_to_int`
   (-45.4%). These are algorithmic removals of O(len) work per operation, not
   micro-optimizations, and they act *only* on string paths.

2. **O1/O3 are not actually inert on this benchmark's string keys.** JudySL and
   JudyHS are built *on top of* JudyL arrays keyed by word-sized chunks of the
   string, so every string lookup performs several JudyL descents — and JudyL
   descends are precisely what O3 (`JU_JPDCDPOP0` word access, every branch
   descend) and O1 (popcount on bitmap leaves) accelerate. The C-level gate
   measured "null on strings" against the **`urand16` high-entropy** corpus,
   where the underlying JudyL arrays are sparse and hit linear leaves.
   `judy-bench.php` uses **low-entropy structured keys** (`key:0`, `key:1`, …),
   whose chunked words are dense and clustered — a materially higher popcount
   and branch duty cycle. Transferring a null result from `urand16` to these
   keys is a corpus-shape extrapolation, not a measurement.

**Quantitative pre-registration** (medians, cache-resident, 300k, core.int + core.str):

- S-vs-C int/bitset-keyed median: **at least 8% faster** (I expect ~10-18%).
- S-vs-C string-keyed median: **at least 10% faster** (I expect ~15-22%).
- B-vs-S median, either family: **under 10%**, and roughly key-type-agnostic.
- S-vs-C will account for **more than half** the phase-1 B-vs-C effect
  (in log-ratio terms) in BOTH families.

**What would falsify me / confirm the coordinator**: B-vs-S carrying more than
half the effect in both families, or S-vs-C string median inside the ~3% floor.
If that happens, the coordinator is right, the headline must be reattributed to
linkage, and my reasoning above is wrong — most likely because the PLT and
hardening overhead per Judy call is far larger than I estimate.

**What would falsify the coordinator**: S-vs-C carrying the majority in both
families, in which case the string gains are genuinely ours (O4 plus O1/O3
acting through the JudyL layer beneath JudySL/JudyHS) and the "null on strings"
expectation was a corpus-shape artifact of `urand16`.

**Either way**: the unattributed -17.7% / -22.2% will NOT appear in BENCHMARK.md
as "the vendoring speedup" without this decomposition printed beside it.

## Result

Measured 2026-08-19, `run5-attribution.json`, exclusive pinned host, hygiene
clean (`failed=false`, `foreign_tenant=false`), php-only control
`-0.08% [-0.21, +0.05]` over 43 rows, 81 FASTER / 0 SLOWER / 13 null. Arm S
verified unpatched at the instruction level (popcnt=0, bswap=12) against arm C
(popcnt=89, bswap=985).

| family | S-vs-C (patches only) | B-vs-C (full delivered) | patch share of the gain |
| --- | --- | --- | --- |
| int/bitset-keyed | **-16.54%** (n=32) | -17.66% (n=33) | **96.5%** |
| string-keyed | **-11.36%** (n=49) | -22.16% (n=57) | **39.8%** |

Patch share is the per-cell median of `ln(S-vs-C ratio) / ln(B-vs-C ratio)` over
matched cells. B-vs-S is not measured directly; it is the residual, and is
described as such.

### Scoring — this was a split decision, and both predictions lost something

**On integer paths I was right and the coordinator was wrong.** Our source
patches explain **96.5%** of the delivered integer-path gain. Linkage and
hardening flags contribute roughly one percentage point of the -17.66%. The
prediction that S-vs-C would be "small, plausibly single-digit" is falsified for
this family: -16.54% is neither small nor single-digit, and the largest S-vs-C
cells are exactly the integer API operations (`api.equals.int_to_int` -28.3%,
`api.fromArray.int_to_int` -25.7%, `api.putAll.int_to_int` -25.4%).

**On string paths the coordinator's concern was justified and my prediction
failed.** I predicted S-vs-C would carry more than half the effect in BOTH
families. It carries only **39.8%** on strings — the majority of the string
uplift is NOT our source patches. My string threshold (">=10%") was met at
-11.36%, so O4 and the JudyL-layer effect are real and measurable, but they are
not the main story there. Had this been published as "vendoring made string
operations 22% faster because of our patches", it would have been wrong by
roughly a factor of two.

**Both of us were wrong about the shape of the linkage effect.** I predicted it
would be "under 10% and roughly key-type-agnostic"; the coordinator implicitly
predicted the same uniformity. It is strongly key-type-**specific**: about one
point on integer paths versus roughly eleven on string paths.

The most plausible mechanism for that asymmetry — offered as a hypothesis, not a
measurement, since the host has no PMU — is call frequency. `JudySL` and
`JudyHS` are layered *on top of* `JudyL`: one string operation performs several
`JudyL` calls, each an exported-symbol call that in arm B crosses the PLT into a
shared object, whereas in arms S and C it is a direct call inside the
extension's own binary. PLT and hardening overhead therefore scale with the
number of cross-library calls per PHP-level operation, and string operations
make several times more of them than integer operations do. This predicts the
observed asymmetry and is falsifiable: a `-fno-plt` or `-Bsymbolic` build of the
shared arm should close most of the string gap and little of the integer one.

### Consequence for BENCHMARK.md

The headline is split by key type rather than stated once:

- **Integer/bitset paths**: the gain is genuinely ours (96.5% attributable to
  the vendored patches).
- **String paths**: the delivered gain is real and users get all of it, but only
  about 40% is attributable to our source changes; the rest comes from bundling
  the library into the extension instead of linking a distro shared object.

Both numbers are published, with the decomposition beside them.
