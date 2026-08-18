# research/

Standalone C harnesses backing claims made elsewhere in the repo. **Nothing
here ships** — these are not part of the PECL package, are not built by
`make`, and are not loaded by the extension. They exist so that a measured
claim in a doc or an issue can be re-run instead of taken on trust.

Each subdirectory owns one question and names the artifact it supports.

| Directory | Question | Supports |
| --------- | -------- | -------- |
| [`shm-arena/`](shm-arena/) | Can libJudy live in a shared-memory arena, giving an ordered cache shared across FPM workers? | [issue #83](https://github.com/orieg/php-judy/issues/83) — closed, not planned. Five feasibility gates; writer death corrupts the tree 15% of the time (Wilson CI [8.8%, 24.4%]) and macOS has no robust mutexes. `FINDINGS.md` has the per-gate verdicts. |
| [`iteration-cost/`](iteration-cost/) | Is JudySL's ordered-iteration cost the caller-supplied key buffer, or a stateless re-descend from the root? | [issue #85](https://github.com/orieg/php-judy/issues/85) and [BACKEND_EVALUATION.md](../BACKEND_EVALUATION.md). Concluded that the key-reconstruction hypothesis is refuted — `JSLN` flat in key length and flat in working-set size. **That conclusion needs re-deriving before it is relied on again**; see [Re-derivation owed](#re-derivation-owed-the-jsln-flat-in-key-length-claim) below. |
| [`write-probe-cost/`](write-probe-cost/) | Issue #85 step B3 wants ordered traversal to read the value out of the `key_index` cursor. That means locating the `key_index` slot on every write. What does moving the existence probe from JudyHS to JudySL cost the write path? | [issue #85](https://github.com/orieg/php-judy/issues/85) step B3. The probe swap itself is roughly neutral on a hit (+3% at 16-byte keys, −9% at 40-byte) and a large win on a miss (JudySL fails at the first differing byte; JudyHS digests the whole key first). End-to-end random-order overwrite still regresses, because today's `JHSG`+`JHSI` pair reuses one warm structure and the mirrored write touches two. That regression is why the mirror ships behind the opt-in `optimizeIteration` constructor argument rather than on by default: the unmirrored path keeps the `JHSG` probe and this swap never happens on it. |
| [`backend-comparison/`](backend-comparison/) | Should the extension keep libJudy, or move to a modern ordered index? | [BACKEND_EVALUATION.md](../BACKEND_EVALUATION.md). `amdahl.c`/`amdahl.php` bound how much a backend swap could possibly buy through the PHP boundary; `cmp.c` runs ART against JudySL. Verdict: keep Judy. Needs libart cloned alongside — not vendored. |
| [`libjudy-modernization/`](libjudy-modernization/) | Given that we keep Judy, does the incumbent have exploitable headroom — and does realising it mean vendoring libJudy? | [issue #113](https://github.com/orieg/php-judy/issues/113), plus [#131](https://github.com/orieg/php-judy/issues/131) / [#127](https://github.com/orieg/php-judy/issues/127) for the upstream defects it turned up. A first "no headroom" verdict was retracted; round 2 measured popcount-L at 17% cache-resident (JudyL only) and memory-level parallelism at 1.62–1.79x. The decisive finding is correctness, not speed: stock libJudy built with `gcc -O3` silently loses `Judy::BITSET` keys. Verdict: vendor stock 1.0.5 + patches, gated. `FINDINGS.md` has the full record, including the retraction and the negatives. **No harnesses are committed** — they were built in throwaway trees; re-deriving the timings needs them written again. |

## Re-derivation owed: the `JSLN` flat-in-key-length claim

This directory's `iteration-cost/` row used to record the #85 result flatly as
*"refuted the key-reconstruction hypothesis"*. That wording is withdrawn pending
a re-run. **The claim is not refuted and it may well be true — it is simply not
currently established**, and the distinction matters.

**Why.** [#122](https://github.com/orieg/php-judy/issues/122) established that
`make_key()` only ever padded a key *up* and never truncated it, while its format
(`"user:" + 8 digits + ":f" + >=1 digit`) is **16 characters at minimum**. Every
requested length at or below 16 therefore produced the identical 16-byte key. The
`JSLN` sweep's published points are keylen 16 / 24 / 40 / 64, all at or above that
floor, so those specific points *did* vary the independent variable — this is not
a claim that the published numbers are wrong. But two things follow that the
original conclusion did not account for:

1. **The short half of "flat in key length" was never testable.** The harness
   could not emit a key shorter than 16 bytes at all, so "flat" is established
   only across 16→64, over a range where the trie is already several levels deep.
2. **The corpus is degenerate.** Enumerating the full key set: 8 of 16 byte
   positions are invariant, six carry entropy over 10 of 256 values, and 10^6
   keys exactly saturate a 10^6 key space. With `cJU_BRANCHLMAXJPS` = 7 and 10
   populated subexpanses, **every branch in the tree is a `BRANCH_B` bitmap at
   identical density**; `BRANCH_L`, `BRANCH_U` and every transition between them
   are never observed. Whatever `JSLN` does, it was observed in exactly one
   node-type regime. The same defect invalidated a popcount conclusion in
   [#113](https://github.com/orieg/php-judy/issues/113) — see
   [`libjudy-modernization/FINDINGS.md`](libjudy-modernization/FINDINGS.md) §4.

**The generator is still unfixed here.**
[PR #124](https://github.com/orieg/php-judy/pull/124) repaired
`write-probe-cost/probebench.c` (two key shapes split at `keylen = 16`, a
capacity guard, an exact-length assertion), **but `iteration-cost/iterbench.c`
still carries the original `make_key()`** — verify before quoting any figure
from it.

**What would settle it.** Port PR #124's two-shape generator into
`iterbench.c`, then re-run the three #85 discriminators across lengths spanning
both regimes *and* against at least one non-degenerate corpus (uniform-random
bytes, and variable-length), reporting Judy's node-type histogram per corpus so
the regime is visible rather than assumed. If flatness survives that, the
conclusion is re-established and this section goes away — along with the caveat
it forces onto
[BACKEND_EVALUATION.md](../BACKEND_EVALUATION.md#what-would-change-the-verdict),
which carries the same claim.

## Running these

They need libJudy and a C compiler, and they produce timings, so they need an
**idle machine** — check load average before and between runs and treat
anything above cores/2 as contaminated. See BENCHMARK.md's *Environment and
contention* section for why that matters here: a contended sweep of the main
benchmark suite produced two wrong conclusions before being discarded.

```sh
# iteration-cost
gcc -O2 -Wall -Wextra -o iterbench research/iteration-cost/iterbench.c -lJudy
./iterbench 1000000 16 5        # n, key length, reps

# write-probe-cost
gcc -O2 -Wall -Wextra -o probebench research/write-probe-cost/probebench.c -lJudy
./probebench 1000000 16 5       # n, key length, reps; keylen < 8 adds the
                                # ADAPTIVE short-string (SSO) probe
./probebench 200000 6 5         # the SSO probe itself

# shm-arena
cd research/shm-arena && make && make run
```

## probebench key shapes

`probebench` emits two different key shapes, split at `keylen = 16`:

- **`keylen >= 16`** — `user:00001234:f5` padded with `x`, the same shape as
  `iteration-cost/iterbench.c`, so those figures sit next to the ones in
  [#85](https://github.com/orieg/php-judy/issues/85). Ten keys share each
  `user:` prefix.
- **`keylen < 16`** — a fixed-width base-36 counter filling exactly `keylen`
  bytes. The long format is 16 characters at minimum and cannot be squeezed
  shorter while staying unique, so a second shape is required to reach the
  short-key regime at all.

The boundary matters when reading results: a `keylen = 6` run and a
`keylen = 16` run differ in key *shape* as well as key *length*, so trie depth
and fan-out are not directly comparable across it. Within a regime they are.

Before [#118](https://github.com/orieg/php-judy/issues/118) the short shape did
not exist: `make_key()` only padded up, never truncated, so every `keylen`
below 16 silently produced a 16-byte key, and the SSO branch then copied 16
bytes into an 8-byte `Word_t` and aborted. **The `JLG hit (SSO packed)` row had
therefore never been produced**; any pre-#118 note claiming a number for it is
wrong.

## The ADAPTIVE/SSO probe, measured

`(measured)` 2026-08-18, first run of this probe in its life — it aborted for
every `keylen < 8` until [#118](https://github.com/orieg/php-judy/issues/118),
so no earlier figure for it exists and any claim of one predates the fix.

**Environment.** Dedicated Linux x86_64 host — 24 cores, 62 GB, Ubuntu 22.04,
kernel 6.8. Docker `debian:bookworm`, gcc 12.2.0, libJudy 1.0.5-5+b2. Load
average `0.00` before and `0.25` after, nothing above 2% CPU: idle, well under
the cores/2 = 12 threshold.

**Parameters.** `probebench 1000000 6 5` — 1,000,000 keys, `keylen = 6`
(inside the SSO window), 5 reps, median ns/op.

| Probe | ns/op | across two runs |
| --- | ---: | --- |
| `JSLG` hit, random order | **107.8** | 107.76, 108.07 |
| `JHSG` hit, random order | **115.8** | 115.70, 115.77 |
| `JLG` hit (SSO packed), random order | **115.9** | 115.94, 115.96 |

Reproduced across two independent invocations; the SSO row agreed to within
0.02 ns.

**The result contradicts the branch's premise.** The SSO path exists on the
theory that packing a short key into a `Word_t` and reading it from a JudyL
beats hashing it. At 6-byte keys it does not: SSO packed and `JudyHS` are within
noise of each other, and the plain `JudySL` trie beats both by roughly 7%. For
random-order point reads at this key length the ADAPTIVE type's SSO store buys
nothing over `JudyHS`.

**Limits of this number, which are wide.** One key length on one machine. 6 sits
mid-range of the SSO window (1-7) and nothing here says where the curve
crosses, if it does. It also must not be read next to the 16-byte rows
elsewhere in this directory: the short regime uses a different key shape (see
above), so trie depth and fan-out differ, not just key length. A sweep across
`keylen` 1-7 is what would turn this into an answer rather than a data point.

## Reading probebench's absolute numbers

The ns/op figures include real harness overhead and should not be quoted as
pure Judy cost. On a 1,000,000 x 16-byte run, `__strlen_avx2` and
`__strcpy_avx2` over the key array account for ~31% of the run's DRAM misses,
and `snprintf` in `make_key` for ~7% of instructions.

*Relative* comparisons are unaffected — the harness is identical across the
configurations being compared, which is what the flag-matrix study on
[#113](https://github.com/orieg/php-judy/issues/113) relies on.

As with every benchmark in this repo, check machine load before believing a
number: a load average above cores/2, or any non-target process over ~50% CPU,
makes the run uninterpretable.
