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
elsewhere). Point `JUDY_PREFIX` at the vendored bundled build once #142
Stage 1 lands. `validation/build-stock.sh <src> <prefix> [CFLAGS...]` builds
a linkable prefix from pristine 1.0.5 sources (e.g. this repo's `libjudy/src`)
at any flags — that is how the validation below sanitizes the library itself.

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

## CI

Deliberately not wired into CI yet: #142 Stage 1 owns the `ci.yml` rewrite,
and wiring this in now would conflict. The smoke mode is shaped for
`research/ci-smoke.sh` (warning-clean at `-Wall -Wextra -Werror`, sanitized
build target, seconds-scale fixed-seed run, non-zero exit with reproduction
line) and should join it — plus a bundled-tree cell in the extension CI —
once Stage 1 lands. Until Stage 2's P3 lands, a stock system-lib CI cell
must run `--no-bulk`.

Nothing in this directory ships: `research/` is excluded from `package.xml`
by design.
