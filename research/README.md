# research/

Standalone C harnesses backing claims made elsewhere in the repo. **Nothing
here ships** — these are not part of the PECL package, are not built by
`make`, and are not loaded by the extension. They exist so that a measured
claim in a doc or an issue can be re-run instead of taken on trust.

Each subdirectory owns one question and names the artifact it supports.

| Directory | Question | Supports |
| --------- | -------- | -------- |
| [`shm-arena/`](shm-arena/) | Can libJudy live in a shared-memory arena, giving an ordered cache shared across FPM workers? | [issue #83](https://github.com/orieg/php-judy/issues/83) — closed, not planned. Five feasibility gates; writer death corrupts the tree 15% of the time (Wilson CI [8.8%, 24.4%]) and macOS has no robust mutexes. `FINDINGS.md` has the per-gate verdicts. |
| [`iteration-cost/`](iteration-cost/) | Is JudySL's ordered-iteration cost the caller-supplied key buffer, or a stateless re-descend from the root? | [issue #85](https://github.com/orieg/php-judy/issues/85) and [BACKEND_EVALUATION.md](../BACKEND_EVALUATION.md). The key-reconstruction hypothesis stays refuted, but the evidence originally given for it does not: **`JSLN` is not flat in key length, and not flat in working-set size** — both flat results were artifacts of one degenerate corpus. Re-derived on a fixed generator; see [The `JSLN` flatness claim, re-derived](#the-jsln-flatness-claim-re-derived) below. |
| [`write-probe-cost/`](write-probe-cost/) | Issue #85 step B3 wants ordered traversal to read the value out of the `key_index` cursor. That means locating the `key_index` slot on every write. What does moving the existence probe from JudyHS to JudySL cost the write path? | [issue #85](https://github.com/orieg/php-judy/issues/85) step B3. The probe swap itself is roughly neutral on a hit (+3% at 16-byte keys, −9% at 40-byte) and a large win on a miss (JudySL fails at the first differing byte; JudyHS digests the whole key first) — but see [Absent keys and divergence depth](#absent-keys-and-divergence-depth): the miss figure was taken at the one divergence depth most favourable to the trie, so for long keys it is granted rather than measured. End-to-end random-order overwrite still regresses, because today's `JHSG`+`JHSI` pair reuses one warm structure and the mirrored write touches two. That regression is why the mirror ships behind the opt-in `optimizeIteration` constructor argument rather than on by default: the unmirrored path keeps the `JHSG` probe and this swap never happens on it. |
| [`backend-comparison/`](backend-comparison/) | Should the extension keep libJudy, or move to a modern ordered index? | [BACKEND_EVALUATION.md](../BACKEND_EVALUATION.md). `amdahl.c`/`amdahl.php` bound how much a backend swap could possibly buy through the PHP boundary; `cmp.c` runs ART against JudySL. Verdict: keep Judy. Needs libart cloned alongside — not vendored. |
| [`libjudy-modernization/`](libjudy-modernization/) | Given that we keep Judy, does the incumbent have exploitable headroom — and does realising it mean vendoring libJudy? | [issue #113](https://github.com/orieg/php-judy/issues/113), plus [#131](https://github.com/orieg/php-judy/issues/131) / [#127](https://github.com/orieg/php-judy/issues/127) for the upstream defects it turned up. A first "no headroom" verdict was retracted; round 2 measured popcount-L at 17% cache-resident (JudyL only) and memory-level parallelism at 1.62–1.79x. The decisive finding is correctness, not speed: stock libJudy built with `gcc -O3` silently loses `Judy::BITSET` keys. Verdict: vendor stock 1.0.5 + patches, gated. `FINDINGS.md` has the full record, including the retraction and the negatives. **No harnesses are committed** — they were built in throwaway trees; re-deriving the timings needs them written again. |

## The `JSLN` flatness claim, re-derived

`(measured)` 2026-08-17. **Verdict: `JSLN` is not flat in key length, and not
flat in working-set size.** Both flat results reproduce exactly — but only on
the structured corpus, and only inside the window that was actually swept. Off
that corpus they fail by margins far larger than the noise floor.

The underlying #85 *conclusion* — that per-key key reconstruction is not what
makes ordered `JSLN` traversal expensive — survives, and is in fact better
supported now than it was. What does not survive is the flatness evidence
offered for it, and any use of "flat" as a general property of `JSLN`.

### Why this needed re-deriving

[#122](https://github.com/orieg/php-judy/issues/122) established that
`make_key()` only ever padded a key *up* and never truncated it, while its
format (`"user:" + 8 digits + ":f" + >=1 digit`) is **16 characters at
minimum**. Every requested length at or below 16 therefore produced the
identical 16-byte key:

```
old  keylen= 4 -> strlen=16  user:00123456:f7
old  keylen= 6 -> strlen=16  user:00123456:f7
old  keylen=12 -> strlen=16  user:00123456:f7
old  keylen=16 -> strlen=16  user:00123456:f7
old  keylen=24 -> strlen=24  user:00123456:f7xxxxxxxx
```

The published sweep points are keylen 16 / 24 / 40 / 64, all at or above that
floor, so those points *did* vary the independent variable — this was never a
claim that the published numbers were wrong, and they reproduce here. But the
short half of the sweep had never executed, and the corpus was degenerate: 8 of
16 byte positions invariant, six carrying 10 of 256 values, 10^6 keys exactly
saturating a 10^6 key space, so every branch in the tree is a `BRANCH_B` bitmap
at identical density.

`iterbench.c` now carries the same two-shape generator as
`write-probe-cost/probebench.c` ([PR #124](https://github.com/orieg/php-judy/pull/124)),
plus an unconditional `key_check()` that aborts if an emitted key is not
exactly the requested length — `assert()` is not used, because a generator that
silently ignores its length argument is the whole defect. It also takes an
optional fourth argument selecting the corpus, so the conclusion is no longer
scoped to one key shape:

- **`struct`** (default) — the original shape above 16 bytes, a mixed
  fixed-width base-36 counter below it. Byte-for-byte identical to
  `probebench.c`.
- **`rand`** — uniform-random bytes, exactly `keylen` of them, drawn from
  1..255. One shape across the whole sweep, so there is no regime break at 16
  and key length is the only variable.
- **`varlen`** — uniform-random bytes with the length drawn uniformly from
  `[4, keylen]`.

### Method

**Environment.** Dedicated Linux x86_64 host — 24 cores, 62 GB, kernel 6.8.
Docker `php-judy-bench:latest` (Debian 13, gcc 14.2.0, libJudy 1.0.5-5.1),
`gcc -O2 -Wall -Wextra`. Load average was 0.00 before the first run and never
exceeded 1.00 (the benchmark is single-threaded, so 1.00 *is* the job) —
checked before every one of the 36 configurations.

**Sampling.** `iterbench 1000000 <keylen> 7 <corpus>`, five independent
processes per configuration, seven reps each — 35 samples per point. Reported
figure is the median of the five process medians. Process-to-process median
spread was ≤ 5% for 22 of the 24 key-length configurations, and 9.1% at worst.

**Tolerance for "flat".** A quantity counts as flat across a swept range if its
median varies by **≤ 10%** end to end. That is roughly twice the worst
process-to-process spread observed and about double the typical one, so a
change that clears it is not noise. This threshold is stated up front rather
than fitted afterwards.

### Key length, at fixed n = 1,000,000

`JSLN` is the ordered walk; `JSLG` replays the same keys in the same order as
point lookups — same descend, same locality, no key written back — so
`JSLN − JSLG` is the discriminator #85 leaned on hardest.

| keylen | `JSLN` struct | `JSLN` rand | `JSLN` varlen | Δ struct | Δ rand | Δ varlen |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 4 | 30.8 | 31.8 | 31.7 | 10.2 | 10.2 | 10.1 |
| 5 | — | 32.2 | — | — | 10.1 | — |
| 6 | 31.9 | 32.8 | 39.3 | 10.7 | 10.2 | 13.8 |
| 7 | — | 35.2 | — | — | 12.5 | — |
| 8 | 113.6 | 102.1 | 55.0 | 31.5 | 35.0 | 18.0 |
| 9 | — | 114.4 | — | — | 40.6 | — |
| 10 | — | 114.6 | — | — | 40.7 | — |
| 12 | 124.1 | 116.1 | 87.3 | 31.3 | 42.1 | 32.2 |
| 16 | 70.3 | 115.5 | 98.7 | 32.9 | 41.6 | 37.2 |
| 24 | 78.9 | 118.1 | 114.4 | 31.4 | 43.5 | 46.6 |
| 40 | 78.5 | 125.4 | 126.6 | 32.4 | 45.8 | 54.2 |
| 64 | 78.7 | 132.8 | 140.5 | 33.8 | 50.5 | 56.1 |

Median ns/key; Δ is `JSLN − JSLG`. The widest min..max band across all 35 reps
of any single row is ±4%, so no two rows differing by more than ~8% overlap.

**Against the 10% tolerance:**

| Claim | Corpus and range | Measured | Flat? |
| ----- | ---------------- | -------- | ----- |
| `JSLN` flat in key length | struct, 16→64 | 70.3 → 78.7, **+12%** | marginally no (published as +10%) |
| `JSLN − JSLG` flat | struct, 16→64 | 32.9 → 33.8, **+3%** | **yes** — reproduces |
| `JSLN` flat in key length | rand, 16→64 | 115.5 → 132.8, **+15%** | no |
| `JSLN − JSLG` flat | rand, 16→64 | 41.6 → 50.5, **+21%** | no |
| `JSLN` flat in key length | rand, 4→64 | 31.8 → 132.8, **4.2x** | no |
| `JSLN` flat in key length | varlen, 8→64 | 55.0 → 140.5, **+155%** | no |

So the flat result is not merely untested below 16 — it is **specific to the
degenerate corpus**. Swept over the same 16→64 window that was originally
published, but with uniform-random keys instead of `user:%08lu:f%lu`, the
discriminator rises 21%.

### The step at 8 bytes

The largest key-length effect in the whole study sits in the half that had
never run. On the `rand` corpus, `JSLN` is 35.2 ns at keylen 7 and 102.1 ns at
keylen 8 — a **2.9x discontinuity across one byte** — then essentially level
at 114.4 / 114.6 for 9 and 10.

That is the machine word boundary: a key of 7 bytes plus its NUL fits in a
single `Word_t`, so JudySL resolves it in one JudyL level; at 8 bytes it needs
a second. It is the same boundary the `*_ADAPTIVE` types use for their packed
short-string path, and it is invisible to any sweep that starts at 16.

Above the step, growth is real but sub-linear. Least-squares over keylen 8→64
on `rand` gives **0.44 ns/byte** for `JSLN`, **0.22 ns/byte** for `JSLG`, and
**0.22 ns/byte** for the delta — 8x the bytes buys +30% on `JSLN`, not 8x.

### Working-set size, at fixed keylen = 16

| n | `JSLN` struct | `JSLN` rand |
| ---: | ---: | ---: |
| 100,000 | 67.6 | 48.3 |
| 316,228 | 67.3 | 72.6 |
| 1,000,000 | 67.7 | 115.9 |
| 3,162,278 | 71.6 | 134.7 |

`struct` reproduces the published result — +0.1% over 100K→1M, +6% out to
3.16M, flat by any tolerance. `rand` over the same 100K→1M range is
**+140%**, and **+179%** out to 3.16M. Flatness in working-set size is an
artifact of the structured corpus, which shares one `user:` prefix across every
10 keys and compresses into a tree that barely grows with n.

### What this does and does not change

**Refuted as stated.** "`JSLN` is flat in key length and flat in working-set
size" is wrong as a property of `JSLN`. It is true only of the
`user:%08lu:f%lu` corpus, and for key length only over 16→64.

**The #85 conclusion survives — on different evidence.** The hypothesis under
test was that ordered traversal is expensive because `JSLN` materialises each
key into a caller buffer, and is therefore fixable inside php-judy. Three
things still argue against it, more strongly than flatness did:

1. **The delta is far too shallow to be byte copying.** 0.22 ns/byte marginal:
   8x the key bytes costs +44% on the delta, not 8x.
2. **The delta grows with n at fixed key length** — 23.0 → 61.7 ns as n goes
   100K → 3.16M on `rand`, at a constant keylen 16. Key reconstruction cannot
   depend on working-set size; a memory-bound successor search can. This is
   evidence the old flat reading could not produce, because the structured
   corpus does not grow.
3. **The delta is a roughly constant *share* of `JSLN`, not a constant cost** —
   34-38% across keylen 8→64 on `rand`. It tracks the traversal, rather than
   the key.

The `JSLN − JSLG` delta was therefore never a clean measure of key
reconstruction: it also contains the successor search, and that is what its n
dependence exposes. The right reading of it is "descend plus successor", not
"key bytes".

**The decomposition figure is withdrawn.** *"~37 ns stateless root descend +
32 ns successor search + ~1 ns of key bytes"* was derived on the structured
corpus at keylen 16 and does not generalise: the marginal per-byte term is
0.22 ns/byte, all three terms move with corpus, and the second term moves with
n. Quote the slope, not the split.

**`BACKEND_EVALUATION.md` is unaffected in direction.** Ordered `JSLN`
traversal is a genuine JudySL property that php-judy cannot call its way out
of — and on a realistic corpus it is *more* expensive than the structured
numbers suggested (115.9 vs 67.7 ns/key at n = 1M, keylen 16), not less.

### Limits

- One host, one libJudy build, one n per key-length sweep. Absolute ns/op
  include harness overhead (see *Reading probebench's absolute numbers* below,
  which applies equally here) and should not be quoted as pure Judy cost.
- **No node-type histogram.** `Judy.h` exposes no way to count `BRANCH_L` /
  `BRANCH_B` / `BRANCH_U` populations from outside the library, so the claim
  that `rand` and `varlen` are non-degenerate rests on the corpora's
  construction — every byte position carries ~8 bits, so no position can be
  invariant — rather than on a measured histogram. Getting the histogram needs
  the vendored tree that [#113](https://github.com/orieg/php-judy/issues/113)
  is gated on.
- `rand` keys diverge within the first two or three bytes whatever their
  length, so above the 8-byte step it varies bytes-to-copy far more than it
  varies trie depth. `varlen`, which does vary depth, rises about 2.7x faster
  per byte. Neither is "the" realistic corpus; the point is that the two
  non-degenerate ones agree with each other and disagree with `struct`.

## Running these

They need libJudy and a C compiler, and they produce timings, so they need an
**idle machine** — check load average before and between runs and treat
anything above cores/2 as contaminated. See BENCHMARK.md's *Environment and
contention* section for why that matters here: a contended sweep of the main
benchmark suite produced two wrong conclusions before being discarded.

```sh
# iteration-cost
gcc -O2 -Wall -Wextra -o iterbench research/iteration-cost/iterbench.c -lJudy
./iterbench 1000000 16 5        # n, key length, reps, corpus (default struct)
./iterbench 1000000 16 5 rand   # uniform-random bytes — the non-degenerate one
./iterbench 1000000 16 5 varlen # random bytes, length uniform in [4, keylen]

# write-probe-cost
gcc -O2 -Wall -Wextra -o probebench research/write-probe-cost/probebench.c -lJudy
./probebench 1000000 16 5       # n, key length, reps, corpus, absent-key
                                # divergence (defaults: struct, offset);
                                # keylen < 8 adds the ADAPTIVE short-string
                                # (SSO) probe
./probebench 200000 6 5         # the SSO probe itself
./probebench 1000000 16 5 rand  # same corpus options as iterbench
./probebench 1000000 16 5 struct last   # absent keys that diverge at the
                                        # final byte, not at byte 5

# shm-arena
cd research/shm-arena && make && make run
```

Both harnesses take the corpus as their fourth argument, with the same three
names and the same generation code, so a figure from one sits on the same
curve as a figure from the other. `probebench` takes the absent-key
divergence as a fifth.

## The CI gate

`research/ci-smoke.sh` builds every harness here with `-Wall -Wextra -Werror`
and runs a small ASan/UBSan pass across the whole parameter grid — every
corpus, key lengths bracketing 8 and 16, and every absent-key divergence.
`.github/workflows/ci.yml`'s `build-research` job runs it on every PR, ending
with a negative control that reinstates the [#122](https://github.com/orieg/php-judy/issues/122)
generator bug and requires the grid to reject the tree.

It exists because this directory had no CI at all, and that is how both of the
defects recorded above survived: nothing compiled `research/`, so a generator
could ignore its own length argument for years and a documented probe path
could ship without ever having executed. Run it before committing anything
here:

```sh
./research/ci-smoke.sh          # n = 1000; seconds, not minutes
```

Two things it does not cover, both deliberately:

- **`backend-comparison/cmp.c`** is not built. It includes `art.h` from
  libart, which is cloned alongside rather than vendored, and vendoring a
  dependency into a tree whose whole point is that nothing in it ships is the
  wrong trade.
- **`shm-arena/`** is compiled but not run. Its gates fork, kill writers
  mid-write and probe robust mutexes; that is a feasibility study, not a smoke
  test, and issue #83 is already closed on its results.

## The structured corpus's key shapes

Both `probebench` and `iterbench`'s default `struct` corpus emit the same two
key shapes, split at `keylen = 16` — the generators are byte-for-byte identical
so the two harnesses' figures sit on one curve:

- **`keylen >= 16`** — `user:00001234:f5` padded with `x`, the shape used
  throughout [#85](https://github.com/orieg/php-judy/issues/85). Ten keys share
  each `user:` prefix. This is the degenerate corpus — see
  [the re-derivation](#the-jsln-flatness-claim-re-derived) for what it hides.
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

The short shape also has to spread the index across all `keylen` bytes rather
than encode it directly. Base-36 needs only `ceil(log36(n))` digits, so a direct
encoding left the leading bytes uniformly `'0'` — four of them at n = 500k and
`keylen` 8, eight at `keylen` 12. That is a degenerate corpus for anything
trie-shaped, and it made length and entropy impossible to vary independently.
`make_key()` therefore multiplies the index by a capacity-scaled constant
coprime with 36 before encoding: still a bijection, so keys stay unique and the
capacity guard still bounds the space exactly.

## The ADAPTIVE/SSO probe, measured

`(measured)` 2026-08-18. This probe aborted for every `keylen < 8` until
[#118](https://github.com/orieg/php-judy/issues/118), so no figure for it
existed before then.

**These numbers supersede an earlier set taken on a degenerate corpus.** The
short-key generator encoded the index directly, and base-36 needs only
`ceil(log36(n))` digits — so at n = 500k every `keylen`-8 key carried four
identical leading `'0'`s and every `keylen`-12 key eight. Length varied while
entropy stayed fixed, and JudySL compresses a shared run, so the trie was handed
a corpus that flattered it. The generator now spreads the index across every
byte (see the mix in `make_key()`); the earlier numbers should not be quoted.

**Environment.** Dedicated Linux x86_64 host — 24 cores, 62 GB, Ubuntu 22.04,
kernel 6.8. Docker `debian:bookworm`, gcc 12.2.0, libJudy 1.0.5-5+b2. Load
average `0.00` throughout, nothing above 2% CPU.

**Parameters.** `probebench 500000 <keylen> 5`, median ns/op, random-order hits.
n is held at 500,000 across the sweep so length is the only variable —
`keylen` 4 is the floor at which base-36 can hold n present plus n absent keys.

| keylen | `JHSG` (hash) | `JSLG` (trie) | `JLG` (SSO packed) |
| ---: | ---: | ---: | ---: |
| 4 | 102.1 | 110.3 | **100.6** |
| 5 | **101.5** | 109.4 | 104.7 |
| 6 | **111.7** | 111.7 | 112.1 |
| 7 | **103.1** | 111.2 | 107.9 |
| 8 | **116.6** | 142.8 | — (SSO is `keylen < 8`) |
| 12 | 150.0 | **141.3** | — |

**What it says.**

- **SSO's advantage is real but tiny and narrow.** It wins only at `keylen` 4,
  by ~1.4% over `JudyHS`. At 5 and 7 it is 3-5% slower, and at 6 the three are
  indistinguishable. Nothing here justifies choosing a type for the SSO path.
- **`JudyHS` beats the trie for random point lookups at 4-8 byte keys**, by
  6-8% at 4-7 and by 22% at 8. This supports the existing guidance that the
  `_HASH` types are the ones to reach for on point lookups.
- **The trie overtakes by `keylen` 12** (141.3 vs 150.0). Where the crossover
  sits between 8 and 12 is not measured here.

**Limits.** One machine, one n, one value distribution. Only random-order hits;
iteration-order and miss probes are in the full output and behave differently.
`keylen` 1-3 cannot reach n = 500,000 at all — base-36 over 3 bytes holds 46,656
keys — so the sweep starts at 4 rather than 1, and any run at those lengths is
cache-resident and not comparable. Above `keylen` 12 the index space exceeds
`ULONG_MAX`, so a 64-bit index cannot fill the leading digits and some padding
returns; that is a property of the index type, not of Judy.

Note also that `keylen >= 16` switches to the long key shape (see above), so the
16-byte rows elsewhere in this directory are not on the same curve as this table.

## Absent keys and divergence depth

`probebench`'s miss cases exist to compare a trie miss against a hash miss. A
trie can fail at the first byte that differs from anything stored; a hash has
to digest the whole key before it can answer. So how deep into the key the
absent keys diverge is not a detail of the corpus — it is the independent
variable of that comparison, and holding it at one value reports a point where
a curve is needed.

The original generator held it at one value, and at the value most favourable
to the trie. Absent keys were the present ones offset by `ABSENT_OFFSET_LONG`
(500,000,000), which pushes `i / 10` into 8-digit territory and therefore
changes the third digit of the `user:%08lu` field. At `keylen >= 16` **every**
absent key diverged at byte 5 of 16 — the earliest position the format can
express — while JudyHS still digested all 16 bytes. For long keys the miss
comparison was granted to the trie by construction rather than measured, and
the generator's comment asserted the opposite.

The fifth argument now selects it:

| value | absent key first differs at |
| ----- | --------------------------- |
| `offset` (default) | wherever the historical index offset happens to put it — byte 5 of 16 for the long shape |
| `shallow` | byte 0 |
| `mid` | byte `len / 2` |
| `deep` | byte `len - 2` |
| `last` | the final byte |

The four named modes copy a stored key and change exactly one byte at that
position, so the absent key keeps the stored key's length: the hash's work is
held constant while the trie's varies, which is the contrast the comparison
is about. `offset` is kept as the default so every miss figure published
before this option existed stays reproducible without a flag.

Two limits. The realised divergence is a **lower bound**, not an exact depth:
the mutated key shares `depth` bytes with its source, but some other stored key
may share more, and Judy exposes no way to read back the longest common prefix
it actually walked. And **the curve has not been run** — this change makes the
measurement possible and states that the existing long-key miss number is a
best case; it does not replace that number. Doing so needs an idle host, per
the warning above.

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
