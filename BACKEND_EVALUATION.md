# Backend Evaluation: should php-judy still be built on Judy?

**Scope**: whether the C data structure underneath this extension should be
replaced by a more modern ordered index (ART, Masstree, HOT, Wormhole).

**Audience**: maintainers. This is not a user-facing "should I use Judy"
guide — that is [BENCHMARK.md](BENCHMARK.md), which compares php-judy against
the alternatives a *PHP application* would choose (APCu, `SplFixedArray`,
sorted arrays). This document asks the different question of what the
*extension itself* should be built on.

**Superseded when**: a backend swap is actually undertaken, or when these
measurements are redone against a different ART implementation, a different
key distribution, or integer-keyed workloads (see
[What would change the verdict](#what-would-change-the-verdict)).

**Verdict as measured: keep Judy.** ART ties on point lookup, costs 27% more
memory, and wins only a constant factor on prefix operations. The evaluation
did surface one actionable finding that needs no backend change — see
[The useful finding](#the-useful-finding-iteration-cost-is-an-api-shape-problem).

---

## Why this question comes up

Judy is a ~2002 HP design. The ordered in-memory index literature has moved on:
ART (Leis et al., ICDE 2013), Masstree (Mao et al., EuroSys 2012), HOT (Binna
et al., SIGMOD 2018), Wormhole (Wu et al., EuroSys 2019). If one of those is
materially better on the axes php-judy sells — memory efficiency and ordered
operations — the extension is carrying a legacy dependency for no reason.

## Method

Two steps, cheapest first, because step 1 can make step 2 unnecessary.

### Step 1: bound the achievable win before measuring any replacement

If the PHP extension boundary dominates the cost of an operation, then no
backend can help and the question is closed without implementing anything.
This is an Amdahl bound and it costs almost nothing to derive.

Measured at n=1,000,000, `INT_TO_INT`, random point lookups, 5M reps:

| Layer | ns/op |
| ----- | ----- |
| Raw C `JudyLGet` (including loop + key generation) | 17.39 |
| PHP interpreter floor (same loop, no Judy access) | 15.97 |
| PHP loop + `$j[$k]` | 39.30 |
| **Marginal cost of the Judy access through PHP** | **23.33** |

The extension boundary — `offsetGet` dispatch plus zval marshalling — is
therefore roughly **6-8 ns**, and the C structure is **~65-75% of the marginal
cost** of an operation.

**This result permits the investigation to continue.** Had the boundary
dominated, a faster structure would have been invisible from PHP. It does not,
so a materially faster backend would show up end to end.

It also sets the ceiling. Judy work is ~40% of the total 39.30 ns operation, so
in this synthetic tight loop an *infinitely fast* replacement caps out at ~39%
improvement, and a realistic 2x structure yields ~19%. In application code —
where surrounding PHP work dwarfs both terms — the visible gain is smaller
again.

### Step 2: head-to-head in pure C

No PHP extension was written. Comparing the libraries directly answers the
performance question at a fraction of the cost, and if the candidate does not
win in C it cannot win through a binding.

ART implementation: [armon/libart](https://github.com/armon/libart), the
reference C99 implementation. Compared against JudySL, since the workload of
interest is ordered string keys with prefix operations.

## Results

Environment: dedicated idle x86_64 Linux host, 24 cores, 62 GB, Ubuntu 22.04,
4 KB pages, load average 0.00-0.04 throughout (threshold cores/2 = 12.0).
`gcc -O2 -Wall -Wextra`, zero warnings. 1,000,000 keys of the form
`user:%08lu:f%lu` — 10 keys per user, so a prefix names a real 10-key group.
3 runs per structure; the spread across runs was within a few percent on every
metric.

All figures `(measured)`.

| Metric | JudySL | ART | Winner |
| ------ | ------ | --- | ------ |
| Insert | ~124 ns/op | ~110 ns/op | ART 1.13x |
| **Point lookup** | ~179 ns/op | ~174 ns/op | **tie (3%)** |
| Prefix scan (10-key group) | ~1.34 µs | ~0.54 µs | ART 2.5x |
| Ordered iteration | ~67 ns/key | ~2.7 ns/key | ART 25x — *see caveat* |
| **Peak RSS** | **51.8 MB** | 65.8 MB | **Judy 1.27x** |

Reading these:

- **Point lookup is a tie.** This is the metric ART is best known for, and on
  this key shape it does not separate from Judy. The primary motivation for a
  swap is absent.
- **Memory moves the wrong way.** ART costs 27% more. Memory efficiency is
  php-judy's headline claim, so a swap would regress the thing the project
  sells hardest.
- **Prefix is a constant factor, not a class change.** Both structures walk
  only the matching slice; BENCHMARK.md already measures php-judy at 4,212x
  over APCu on this workload, so 2.5x on top does not change any strategic
  conclusion.

### The iteration number: first explanation was wrong

The initial reading of the 25x gap was that it is an artifact of API shape —
`JSLN` materializes the next key into a caller-supplied buffer on every step,
while `art_iter` performs a DFS and hands a callback a pointer. On that
reading the cost would be per-key key reconstruction, and therefore fixable
inside php-judy with no backend change.

**That hypothesis was measured and refuted** (see
[issue #85](https://github.com/orieg/php-judy/issues/85)). Three
discriminators, all against it:

| Test | Expectation if key materialization dominates | Measured |
| ---- | -------------------------------------------- | -------- |
| Key length 16 → 64 bytes (4x the bytes to write) | ~proportional rise | 70.5 → 77.8 ns, **+10%** |
| n 100K → 1M (10x working set) | cache effects | 66.4 → 70.2 ns, **+6%** |
| `JSLN − JSLG` replayed in iteration order — same descend and locality, no key written back | falls with shorter keys | 33.1 / 30.7 / 31.1 / 32.9 ns at keylen 16/24/40/64 — **flat** |

The actual decomposition of that ~70 ns is roughly **37 ns stateless root
descend + 32 ns successor search + ~1 ns of key bytes.** The cost is JudySL's
structure and libJudy's *stateless* API — every `JSLN` re-descends from the
root — not the caller-supplied buffer.

This makes the gap **more** significant, not less. `Judy.h` exposes no cursor
or iterator primitive, so there is no cheaper call for php-judy to switch to:
this is a genuine backend property that the extension cannot optimize away.
ART's callback iteration is structurally preferable here, not incidentally
faster.

It does not change the verdict — memory still favours Judy by 27% and point
lookup is still a tie — but it is the one axis on which a backend swap would
buy something real, and it should be weighed by anyone revisiting this.

## What the investigation found instead

Decomposing `forEach()` at the PHP level did surface a large addressable cost,
in a different place than predicted: the four `*_HASH` / `*_ADAPTIVE` types
perform a **second full lookup per element** during ordered traversal — 22
ns/element at 16-byte keys, 98 ns/element (46% of `forEach()`) at 40-byte keys.
That is an extension-level fix requiring no backend change, tracked in
[#85](https://github.com/orieg/php-judy/issues/85).

The same measurement retired the roadmap item's original framing: "vtable
dispatch" targets a few nanoseconds inside a 6-15 ns glue bucket, and userland
callback dispatch is only ~10 ns. `Judy::forEach()` on `INT_TO_INT` (26.4
ns/element) already beats `array_map()` over a native PHP array (29.0).

## Why Masstree, HOT, and Wormhole do not apply

These were considered and rejected on design-intent grounds rather than
measured, because their contributions target axes this extension does not
have. Each is a real advance; none of them advances anything php-judy is
constrained by.

**Masstree — optimized for concurrent multicore throughput.** It is a
B+tree-of-tries built around fine-grained optimistic concurrency so that many
cores can read and write one shared structure without serializing. That is its
reason to exist. PHP's execution model gives each worker process its own
structure: there is exactly one thread touching a given Judy array, and no
cross-process sharing. Adopting Masstree would mean paying for concurrency
control machinery — extra indirection, version validation on read paths — to
protect against contention that cannot occur. The one scenario where its design
would pay off is a structure shared across FPM workers, and that path was
evaluated and closed in
[issue #83](https://github.com/orieg/php-judy/issues/83): a shared-memory Judy
arena turned out to require a concurrency and failure-recovery subsystem larger
than the feature.

**HOT — optimized for long keys with poor prefix structure.** A plain radix
trie's height grows with key length, so long keys mean many pointer hops. HOT's
contribution is to bound height by letting each node discriminate on a variable
number of bits rather than a fixed byte, keeping fanout high regardless of how
the key bytes are distributed. That is a large win when keys are long and do
not share prefixes usefully. php-judy's keys are the opposite: integers, or
short application strings — cache keys, IDs, namespaced identifiers of a few
tens of bytes, typically with heavy shared prefixes, which is the case ordinary
radix compression already handles well. The height problem HOT solves is not
one this extension has.

**Wormhole — optimized for asymptotics on very long keys at large N.** It is a
hash table / trie / B+tree hybrid whose headline property is lookup cost
scaling with key *length* rather than with the number of keys, and it is
likewise built with concurrent access in mind. That trade pays off when N is
very large and keys are long. In php-judy, N is bounded by what a single worker
can hold in memory, and keys are short — so the asymptotic improvement applies
to a regime the extension does not operate in, while the structural complexity
applies always.

The common thread: all three spend complexity on **concurrency** and **long-key
asymptotics**. php-judy is single-threaded per process with short keys, and its
competitive axis is **memory footprint**, where Judy already measures well —
ART, the one candidate actually benchmarked here, lost that axis by 27%.

There is also a maintenance dimension. libJudy is a stable, packaged system
dependency available from apt, apk, and Homebrew. These are research
prototypes; adopting one means vendoring and maintaining it, in-tree, for the
lifetime of the extension.

## Verdict

**Keep Judy. Do not build a php-ART extension.** On the measured evidence it
ties on lookup, regresses memory 27%, and wins a constant factor on prefix
operations. The Amdahl bound says a backend swap *could* be visible from PHP,
but only for a meaningfully faster structure, and ART is not one here.

## Caveats

Stated plainly, because they bound how far this verdict generalizes:

- **One ART implementation.** armon/libart is the reference C99 implementation,
  not necessarily the fastest. This is not a verdict on ART as an algorithm.
- **One key shape.** Fixed-length, highly structured, heavy shared prefixes —
  a shape both structures handle well. A different key distribution could move
  these numbers.
- **String keys only.** Integer-keyed workloads were not tested. Judy's
  `BITSET`/`INT_TO_INT` memory advantage is strongest there, so the gap would
  most likely widen in Judy's favour — but that is an expectation, not a
  measurement.
- **No deletion or churn workload.** Both structures were measured
  insert-then-read.
- **Values are `void *` on both sides.** A real extension adds zval handling,
  which taxes both equally and shrinks any relative difference.
- **Masstree, HOT, and Wormhole were not benchmarked** — they were excluded on
  design-intent grounds, argued above. That is a reasoned exclusion, not a
  measured one.

## Reproduction

Requires libJudy, gcc, and an **idle** machine — check `cat /proc/loadavg`
before and between runs and treat anything above cores/2 as contaminated.

Both harnesses are committed under
[`research/backend-comparison/`](research/backend-comparison/). libart is not
vendored — clone it alongside:

```sh
cd research/backend-comparison
git clone --depth 1 https://github.com/armon/libart.git

# Step 2 — ART vs JudySL. One structure per process so peak RSS is attributable.
gcc -O2 -Wall -Wextra -I libart/src -o cmp cmp.c libart/src/art.c -lJudy
./cmp judy 1000000
./cmp art  1000000

# Step 1 — the Amdahl bound. Run the PHP arm with the built extension.
gcc -O2 -Wall -Wextra -o amdahl amdahl.c -lJudy
./amdahl 1000000 5000000
php -d extension=../../modules/judy.so amdahl.php 1000000 5000000
```

What each one does:

- **`amdahl.c` / `amdahl.php`** — the same random point-lookup loop in C
  (`JLG`) and in PHP (`$j[$k]`), each with a matching no-access "floor" loop so
  the difference isolates the extension boundary. Keys via xorshift so
  generation cost is identical in both arms and inside both loops.
- **`cmp.c`** — inserts n keys `user:%08lu:f%lu` (10 per user, so a prefix
  names a real group) into either JudySL or an `art_tree`, then times insert,
  random point lookup, full ordered iteration, and a single-group prefix scan,
  reporting peak RSS from `getrusage(RUSAGE_SELF)`.

## What would change the verdict

Any of these would justify redoing this evaluation:

- A measured integer-keyed comparison showing ART competitive on memory.
- A different ART implementation, or a different key distribution, showing a
  real point-lookup separation rather than a 3% tie.
- php-judy acquiring a shared-memory backend, which would make Masstree's
  concurrency design relevant for the first time — currently closed via
  [#83](https://github.com/orieg/php-judy/issues/83).
- **Partially met already**: ordered string iteration is ~25x cheaper in ART,
  and #85 established the cause is JudySL's stateless per-call root descend
  with no cursor primitive available — so php-judy cannot close it. If a
  workload emerges where ordered traversal dominates and memory does not, that
  is the case for revisiting this, and it is the strongest one available.

### Three of those conditions have since been tested

A separate investigation — *"given that we keep Judy, do we vendor it?"* — has
since put evidence against several of the clauses above. Full record:
[`research/libjudy-modernization/FINDINGS.md`](research/libjudy-modernization/FINDINGS.md)
and [issue #113](https://github.com/orieg/php-judy/issues/113). It does **not**
change the keep-Judy verdict, and it did not re-run ART. What it changes here:

- **"No cursor primitive available" is true of the shipped library, not of the
  design.** `JudyPrevNext.c:251-253` already maintains the branch-JP and
  offset history a cursor needs, rebuilds it from the root on every call, and
  discards it; `:156-161` carries upstream's own unimplemented TBD proposing
  exactly this. It would be caller-owned state with **no in-memory layout
  change**. So the clause holds only while php-judy does not vendor libJudy —
  it is not the structural dead end this section implies. The item is gated on
  a leaf-population histogram, which is the one number that decides whether the
  win is real.
- **"A different key distribution" was partially explored** — 13 corpora,
  against libJudy alone rather than head-to-head with ART. The old
  `user:%08lu:f%lu` corpus turned out to be degenerate (every branch a
  `BRANCH_B` at identical density), which is enough to caution against
  generalising any single-corpus result here, including this document's.
- **The #85 flatness claim itself needs re-deriving.** Its harness could not
  emit a key shorter than 16 bytes — see
  [`research/README.md`](research/README.md#re-derivation-owed-the-jsln-flat-in-key-length-claim),
  [#122](https://github.com/orieg/php-judy/issues/122) and
  [PR #124](https://github.com/orieg/php-judy/pull/124). The published 16/24/40/64
  points did vary key length, so this is not a retraction; the short-key half was
  simply never tested, and `iteration-cost/iterbench.c` still carries the
  unfixed generator.

One correctness finding from that investigation is independent of all backend
questions and applies today: stock libJudy 1.0.5 built with `gcc -O3` silently
loses `Judy::BITSET` keys ([#131](https://github.com/orieg/php-judy/issues/131)),
detected by `tests/bitset_immed_cascade_integrity_001.phpt`.
