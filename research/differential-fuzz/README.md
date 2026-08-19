# differential-fuzz — libJudy vs standard-library oracles

Differential fuzzing harness for libJudy, [#142](https://github.com/orieg/php-judy/issues/142)
Stage 4. Drives every Judy family the extension uses against an exact oracle
through randomized-but-reproducible op sequences, and exits non-zero with a
self-contained reproduction line on any divergence.

| Judy | Oracle | Corpora |
| --- | --- | --- |
| Judy1 | `std::set<Word_t>` | uniform, clustered, dense, ffbias, lowent, mixed |
| JudyL | `std::map<Word_t, Word_t>` | same six |
| JudySL | `std::map<std::string, Word_t>` | struct, rand, varlen, boundary, ffbias, mixed |
| JudyHS | `std::unordered_map<std::string, Word_t>` | rand, boundary, ffbias, short, collide, mixed |

Why it exists: [#131](https://github.com/orieg/php-judy/issues/131) — stock
libJudy 1.0.5 built with `gcc -O3` silently loses Judy1 keys: inserted, then
denied by lookup, while `J1C` over-reports. No crash, no error return. A
differential oracle finds exactly that class in seconds, which is why the
vendoring plan carries this harness as a CI invariant gate: it validates the
Stage 2 correctness patches (P1–P7) as they land, and guards every Stage 3
optimization after them.

## What it checks

Ops driven per domain: insert (fresh + overwrite), delete (miss + biased
toward stored keys), lookup (hit + miss), ordered neighbor searches
(First/Next/Last/Prev from arbitrary points), range count (`J1C`/`JLC`),
bulk build (`Judy1SetArray`/`JudyLInsArray`, Count sweep including 31), and
free-all. Return codes and value slots are compared against the oracle on
**every** op — including "new slot is zero-initialized" on
`JLI`/`JSLI`/`JHSI`.

Invariants checked after every 256-op batch, not only at the end:

- **membership agreement** on a probe sample (stored keys must hit, generator
  keys must agree hit/miss), values compared on every hit;
- **full count agreement**: `J1C`/`JLC` over the full range vs oracle size,
  plus random range counts vs an oracle scan.

Every 4096 ops, and at the end of every run: a **full ordered walk compared
element-by-element in both directions** (First/Next from 0, Last/Prev from
~0; `JudySLFirst`/`Next`/`Last`/`Prev` with key-buffer reconstruction for the
string layer), values included. JudyHS, having no order, gets a full
per-key verification pass instead.

Corpus shapes deliberately include what historically hid bugs
([#122](https://github.com/orieg/php-judy/issues/122)/[#139](https://github.com/orieg/php-judy/pull/139)
lesson): the `(rnd()&0xFF)|((i/64)<<8)` clustered shape that reproduces #131's
IMMED_1_15 cascade, 0xFF-biased bytes (the ASan corpus), keys crossing the
8-byte SSO/word boundary (lengths 4..9 exactly), embedded NUL and empty keys
for JudyHS, the empty string for JudySL, the struct/rand/varlen
generators ported from `research/iteration-cost/iterbench.c` post-#139 —
including the #122 truncation fix and the unconditional exact-length check —
and engineered 32-bit hash collisions for JudyHS (`collide`: 2-byte blocks
`Aa`/`BB`/`C#` each contribute the same `c*31+b` increment, so same-length
keys share their full hash and pile into one bucket), added for the O4d
JudyHSDel gate (#142): the delete path's leaf-compare and null-branch guards
only fire under colliding deletes, which random keys essentially never
produce.

Not covered (known gaps, stated rather than implied): `Judy*Empty` /
`Judy*ByCount` APIs are not driven; `JudyMemUsed` is not compared; a key
present in JudyHS but absent from its oracle is only caught probabilistically
(JudyHS cannot be enumerated); no timing measurement of any kind — this is a
correctness tool.

## Usage

```sh
make                 # plain -O2 harness vs system libJudy
make diffuzz-san     # harness instrumented with ASan+UBSan
./diffuzz smoke [ops]                  # fixed seeds, full 48-cell grid (CI shape)
./diffuzz soak <seconds> [seed]        # time-bounded, random seeds
./diffuzz one <domain> <corpus> <seed> [ops]   # reproduce a single cell
./diffuzz list                         # print the grid
```

The seed is printed on every run; any divergence prints a `repro:` command
line that reproduces it exactly (the PRNG is a self-contained splitmix64, so
reproduction holds across platforms and stdlibs). Exit status: 0 ok,
1 divergence, 2 usage/environment error.

`JUDY_PREFIX` selects the library (default `/opt/homebrew` on macOS, `/usr`
elsewhere). `validation/build-stock.sh <src> <prefix> [CFLAGS...]` builds a
linkable prefix from Judy-1.0.5-layout sources at any flags — point it at
this repo's `libjudy/src` to fuzz the bundled tree (what the CI job does),
or at a pristine import to reproduce the validation runs below; sanitizer
CFLAGS are how the library itself gets instrumented. (When pointed at the
patched `libjudy/src`, the script also compiles the php-judy addition TUs
it finds there, so the prefix carries the bundled tree's full API.)

### `MULTIGET=1` — the batched-lookup oracle (#142 patch O5)

`make MULTIGET=1 JUDY_PREFIX=<bundled-tree prefix>` enables a JudyL mode
that cross-checks `JudyLMultiGet` (the bundled tree's AMAC-pipelined
batched lookup) against per-key `JudyLGet`: every 256-op batch and at cell
end, a batch of keys — sizes sweeping the lane-starvation edges (0, 1,
below/at/above the 16-lane default, up to 256), composed of stored keys,
+1 near-misses, in-batch duplicates and generator keys, with occasional
all-hits/all-misses batches — must come back POINTER-identical per slot,
with a matching hit count; result slots are pre-poisoned so an unwritten
slot cannot pass as a miss. Leave `MULTIGET` unset for stock/system
libraries, which lack the symbol. To force the pipelined path onto every
tree (the production entry point falls back to serial probes below its
tiny-tree/tiny-batch thresholds), build the prefix with
`-DcJL_MULTIGET_SERIAL_POP1=0 -DcJL_MULTIGET_SERIAL_COUNT=1`; lane-count
variants build with `-DcJL_MULTIGET_LANES=<1..64>`.

### `--no-bulk` and stock libraries

Against **stock** 1.0.5 (Homebrew, SourceForge) the bulk sweep hits
[#127](https://github.com/orieg/php-judy/issues/127)'s `JudyInsArray`
off-by-one at Count==31: an ASan-instrumented library reports a
global-buffer-overflow; a plain build silently writes 63 words into a
zero-word allocation, and the resulting heap corruption crashes the process
at some *later* allocation (observed locally on macOS: `smoke` dies with
SIGTRAP cells after the corrupting one). That is the harness working as
designed. Use `--no-bulk` to scope a run to the classic APIs on a library
known to carry #127 — e.g. Homebrew's, until Stage 2's P3 lands in the
vendored tree.

## Validation — watched to fail against both bug classes

A green harness on a broken library is worse than nothing, so detection
power is demonstrated against real defects before trusting any green run.
Recorded 2026-08-18, `gcc (GCC) 15.3.0` in the `gcc:15` Docker image
(aarch64), library built from this repo's byte-identical 1.0.5 import at
`libjudy/src` by `validation/build-stock.sh`. Re-run any time with
`validation/run-in-docker.sh` — the full four-stage transcript is
reproduced from the mounted checkout.

**V1 — the #131 miscompile (stock sources, `gcc -O3`).** The compiler
announces the UB it exploits during the build:

```
libjudy/src/JudyCommon/JudyIns.c:1506:51: warning: iteration 8 invokes undefined behavior [-Waggressive-loop-optimizations]
```

and the Judy1 differential fails in the second smoke cell, in seconds, with
the missing-keys signature (inserted key denied by `J1T`):

```
DIVERGENCE domain=judy1 corpus=clustered seed=0xe4528abc53dec306 ops=60000 op#=3583 phase=batch
  membership: key 0x3a4 in oracle, J1T says absent
repro: ./diffuzz one judy1 clustered 0xe4528abc53dec306 60000 --no-bulk
```

**V2 — same sources, `gcc -O2`.** Full 46-cell smoke passes (`smoke OK: 46
cells, no divergence`). `-O2` is load-bearing against stock sources, exactly
as #131 concluded.

**V3 — the #127 `JudyInsArray` off-by-one (ASan-built stock library).** The
bulk sweep's Count==31 build trips it in both domains, harness driving
`Judy1SetArray`/`JudyLInsArray` directly:

```
==509==ERROR: AddressSanitizer: global-buffer-overflow ... READ of size 1
0x...4ea720 is located 0 bytes after global variable 'j__L_LeafWPopToWords' defined in 'JudyLTables.c:187:1' (0x...4ea700) of size 32
```

(and the Judy1 twin at `j__1_LeafWPopToWords`, reported by ASan relative to
the neighboring `j__1_Leaf7PopToWords` global).

**V4 — same ASan build plus the one-line #127 fix**
(`j__udyAllocJLW(Count + 1)` → `j__udyAllocJLW(Count)`). Full smoke
including the bulk sweep passes: 46 cells, no divergence, ASan clean.

Also on record: the harness itself is ASan+UBSan-clean (46-cell smoke via
`diffuzz-san`, library uninstrumented), and a 377-cell × 100k-op `--no-bulk`
soak against Homebrew's stock 1.0.5 (clang-built, so #131-safe) found no
divergence.

**V5 — the O5 `MULTIGET=1` mode, watched to fail (recorded 2026-08-18,
Apple clang, arm64, library built from the patched `libjudy/src` with the
thresholds compiled out so the pipelined path runs on every tree).** Two
deliberately broken `JudyMultiGet.c` builds, each caught in the `multiget`
phase in seconds:

- value-offset break (LEAF_B1 subexpanse popcount over `BitMask` instead
  of `BitMask - 1`): `multiget[2/7] key 0x1e46: batched 0xbe144c038 !=
  serial 0xbe144c030` (`judyl/clustered`, op#=1023);
- DCD-strip break (every narrow-pointer check removed): `multiget[2/16]
  key 0xef9d64a766150042: batched 0x8654d30c0 != serial 0x0`
  (`judyl/dense`, op#=54015) — a batched hit for a key serial `JudyLGet`
  rejects, exactly the miss-path class the DCD checks exist for.

The intact build passes the 48-cell smoke at default thresholds, with the
thresholds compiled out, and at lane counts 1 and 32; ASan+UBSan (library
AND harness instrumented) 48-cell smoke clean; 300 s soak (7300 cells,
thresholds compiled out) clean.

**V6 — the O5-reopen counting partition, watched to fail (recorded
2026-08-18, Apple clang, arm64, thresholds compiled out).** The #142 O5
reopen moved a stable counting partition inside `JudyLMultiGet` (keys
grouped by a discriminating byte, each key carrying its original result
slot through the pipeline). A deliberately broken build with an
off-by-one in the partition's scatter slots (`pslots[pos] = i + 1`
instead of `i` -- results land one slot away) was caught in the
`multiget` phase at op#=255 of the first `judyl/uniform` cell:
`multiget[0/17] key 0x7b122ea940ae576c: batched 0x100fc8040 != serial
0xb04c882a0`. The intact partitioned build passes the 48-cell smoke and
a 300 s soak (6704 cells) clean, and the ASan+UBSan instrumented smoke
clean, all with thresholds compiled out so the partitioned pipeline runs
on every tree.

## CI

The `differential-fuzz` job in `.github/workflows/ci.yml` runs this harness
on every PR against the **bundled** tree (`libjudy/src`, built by
`validation/build-stock.sh` at config.m4's shipped flag set: `-O2 -fno-lto
-fno-unroll-loops -DJU_64BIT`, `-mpopcnt` when accepted), so CI fuzzes the
bytes a release build ships. The job also byte-compares the committed
pre-generated `Judy1Tables.c`/`JudyLTables.c` against fresh generator
output, so the fuzzed and shipped tables cannot drift apart.

Both fuzz steps build the harness with `MULTIGET=1`: the production-flag
grid exercises `JudyLMultiGet`'s serial-fallback contract (the fuzzer's
trees sit below the shipped thresholds), and the sanitized grid compiles
the thresholds out so the pipelined + counting-partition path runs on
every tree, under ASan+UBSan and the per-slot pointer-identity oracle.

**Profile split** — CI runs the bounded profile only; the long soaks stay a
local / pre-release tool:

| where | build | run |
| --- | --- | --- |
| CI, every PR | production flags | `smoke` (48 cells x 60k ops, fixed seeds) + `soak 30` (fresh random seeds each run) |
| CI, every PR | ASan+UBSan, library **and** harness | `smoke` |
| CI, every PR | production flags, #131-class truncation planted | negative control: `smoke` must diverge with the judy1 missing-key signature |
| local, pre-release | any of the above | `soak 300`+ per build, plus `--no-bulk` soaks against system libraries |

The negative control re-creates #131's silent-key-loss behavior at the
source level (truncating the `jp_1Index` immediate copy in `JudyCascade.c`
to 8 bytes) so the gate is watched-to-fail on every run, on any compiler.

`research/ci-smoke.sh` (the `build-research` job) additionally runs the
iterbench/probebench ASan+UBSan grid against the same bundled-tree build via
`JUDY_PREFIX`, alongside its system-library pass.

Nothing in this directory ships: `research/` is excluded from `package.xml`
by design.
