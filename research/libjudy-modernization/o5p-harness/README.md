# O5-reopen gate harness (#142)

The measurement harness for the O5 reopen — the batched-lookup entry point
`JudyLMultiGet` with its in-library counting partition. Committed because
FINDINGS.md's "Reproduction" section records uncommitted harnesses as an
open item; this one is re-runnable from the repo.

| file | role |
| --- | --- |
| `o5pbench.c` | the C bench: serial vs batched on identical **pregenerated** probe streams, plus the old-style (dependent-chain) serial kernel for record comparability. Emits `FEAT` / `MEM` / `GATE` / `RES` rows. Corpora include the `wmix*` heterogeneous family added for the reopen (contiguous, strided, clustered, and 32-bit-ranged dense halves). |
| `o5p-mkarm.sh` | builds one arm and links **five** binaries per arm with seeded randomized object order (the build is the replication unit); feature-checks the arm against its source so a stale build cannot masquerade. |
| `o5p-bench.sh` | runs (trial × cell × arm × build), interleaved, pinned to core 2. |
| `o5p-driver.sh` | full matrix: memory parity, L3, heterogeneous mix, out-of-cache, crossover. Gates its own output on the stability guard. |
| `o5p-analyze.py` | per-build medians + percentile-bootstrap CIs; speedup, partition effect, controls, null checks. |
| `o5p-table.py` | the same, rendered as a markdown gate table. `--exclude-trials` drops trials a run has reason to distrust. |
| `phpbench4.php`, `php4-driver.sh` | PHP-level A/B: `getAll()` batched vs `foreach`, across sparse / mixed / dense / clustered shapes, persisting every raw invocation. |
| `o5p-mincount-probe.sh`, `o5p-residency-sweep.sh` | threshold derivation. |

## Why the stability guard exists

On 2026-08-19 this matrix and a second php-judy benchmark campaign ran
concurrently on the shared host and **corrupted each other**. Both
individually satisfied the project's `loadavg < N/2` hygiene rule — 24
cores, loadavg peaked at 2.87 — because that rule does not model the real
coupling: two memory-bound benchmarks contend for LLC and memory
bandwidth no matter how their cores are pinned.

The corruption was caught by luck (a partial read of a cell disagreed with
the full read). `bench-stability.py` makes it mechanical: the `pre` arm is an
untouched baseline, so a per-trial drift in *it* means the machine changed
under the benchmark, and no ratio computed from those trials is
interpretable. Validated both ways — it flags the collided out-of-cache
sweep (baseline drift 116–142%, `wsparse` 8e6 `pre` 69.5 → 156.6 ns/op)
and clears the L3 sweep (≤3.7%). It also caught a 20% single-trial
excursion in `wmixs50` that eyeballing the ratios had dismissed.

### Two pieces of this harness are general-purpose, and now live in `tools/`

The stability guard and the host lock have nothing to do with batched
lookups and should be reused by **any** benchmark campaign in this repo, so
they moved out of this directory to where the next campaign will find them
(see [CONTRIBUTING.md, *Where code lives*](../../../CONTRIBUTING.md#where-code-lives)):

- [`tools/bench-stability.py`](../../../tools/bench-stability.py) `<csv>...`
  takes any CSV in this harness's row format
  (`arm,seed,corpus,n,trial,kernel,ns_per_op,hits`) and fails when an
  untouched baseline arm drifts across trials. It is the mechanical
  version of the `loadavg` hygiene rule, and strictly stronger — see the
  incident above, where loadavg passed and the data was still garbage.
- [`tools/bench-lock.sh`](../../../tools/bench-lock.sh) is a generic
  `/var/tmp/BENCH_LOCK` mutex for the shared bench host. Any campaign that
  does not take it can silently corrupt, and be corrupted by, one that does.

**Use the lock.** `. bench-lock.sh; bench_lock_acquire <agent> "<what>"`
before any run on the shared host; it refuses to start when another
campaign holds `/var/tmp/BENCH_LOCK`.

**Deploying this harness** now means copying those two files in alongside
it: the drivers source `$O5P/bench-lock.sh` and run `$O5P/bench-stability.py`
from the deploy directory, so `tools/bench-lock.sh` and
`tools/bench-stability.py` have to land there with the rest of the harness.
