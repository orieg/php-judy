# research/

Standalone C harnesses backing claims made elsewhere in the repo. **Nothing
here ships** — these are not part of the PECL package, are not built by
`make`, and are not loaded by the extension. They exist so that a measured
claim in a doc or an issue can be re-run instead of taken on trust.

Each subdirectory owns one question and names the artifact it supports.

| Directory | Question | Supports |
| --------- | -------- | -------- |
| [`shm-arena/`](shm-arena/) | Can libJudy live in a shared-memory arena, giving an ordered cache shared across FPM workers? | [issue #83](https://github.com/orieg/php-judy/issues/83) — closed, not planned. Five feasibility gates; writer death corrupts the tree 15% of the time (Wilson CI [8.8%, 24.4%]) and macOS has no robust mutexes. `FINDINGS.md` has the per-gate verdicts. |
| [`iteration-cost/`](iteration-cost/) | Is JudySL's ordered-iteration cost the caller-supplied key buffer, or a stateless re-descend from the root? | [issue #85](https://github.com/orieg/php-judy/issues/85) and [BACKEND_EVALUATION.md](../BACKEND_EVALUATION.md). Refuted the key-reconstruction hypothesis: `JSLN` is flat in key length and flat in working-set size. |
| [`backend-comparison/`](backend-comparison/) | Should the extension keep libJudy, or move to a modern ordered index? | [BACKEND_EVALUATION.md](../BACKEND_EVALUATION.md). `amdahl.c`/`amdahl.php` bound how much a backend swap could possibly buy through the PHP boundary; `cmp.c` runs ART against JudySL. Verdict: keep Judy. Needs libart cloned alongside — not vendored. |

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

# shm-arena
cd research/shm-arena && make && make run
```
