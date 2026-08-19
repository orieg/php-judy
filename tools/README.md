# tools/

Developer tooling. **Nothing here ships** — none of it is in the PECL package,
none of it is built by `make`, and none of it is loaded by the extension.
`validate-pecl` asserts that with
[`check-package-contents.sh`](check-package-contents.sh).

What separates this directory from [`../research/`](../research/) is
permanence, not subject: `tools/` holds the things CI runs and a contributor
re-runs; `research/` holds what those runs *found*. Every harness below is
compiled — and most are executed — on every PR, which is the whole reason they
moved out of `research/`: unbuilt code rots, and
[#122](https://github.com/orieg/php-judy/issues/122) and
[#118](https://github.com/orieg/php-judy/issues/118) are what that looked like
here.

| Path | What it is | Run by |
| ---- | ---------- | ------ |
| [`ci-smoke.sh`](ci-smoke.sh) | Builds every standalone C harness in the repo at `-Wall -Wextra -Werror`, then runs an ASan/UBSan pass over the whole corpus × key-length × absent-key grid | `build-harnesses` (per PR), plus a second pass against the bundled `libjudy/` tree |
| [`differential-fuzz/`](differential-fuzz/) | libJudy against exact `std::set`/`std::map`/string-map oracles, seeded and reproducible. Validated-to-fail against [#131](https://github.com/orieg/php-judy/issues/131) and [#127](https://github.com/orieg/php-judy/issues/127); see its README | `differential-fuzz` (per PR, bounded profile + planted-#131 negative control) |
| [`check-package-contents.sh`](check-package-contents.sh) | Rejects a built PECL tarball that carries a `tools/` or `research/` path | `validate-pecl` (per PR, with its own negative control) |
| [`iteration-cost/iterbench.c`](iteration-cost/) | Ordered `JSLN` traversal cost vs. `JSLG` point lookups, across three corpora | built and smoke-run by `ci-smoke.sh`; run by hand for figures |
| [`write-probe-cost/probebench.c`](write-probe-cost/) | Cost of moving the write-path existence probe from JudyHS to JudySL, including the ADAPTIVE/SSO packed path and absent-key divergence depth | built and smoke-run by `ci-smoke.sh`; run by hand for figures |
| [`bench-lock.sh`](bench-lock.sh) | `/var/tmp/BENCH_LOCK` mutual exclusion for a shared benchmark host. **Source it before any timing run on a shared box** — two memory-bound campaigns corrupt each other through LLC and memory bandwidth even when both individually satisfy the `loadavg < N/2` rule, which is how a whole gate matrix was invalidated on 2026-08-19 | by hand, from any campaign's driver |
| [`bench-stability.py`](bench-stability.py) | The mechanical version of that hygiene rule, and strictly stronger: fails any cell whose untouched baseline arm drifts across trials, so a contaminated run cannot be read as a result. Takes any CSV emitting `arm,seed,corpus,n,trial,kernel,ns_per_op,hits` with a `pre` arm | by hand, or gated in a campaign driver |
| [`backend-comparison/`](backend-comparison/) | `amdahl.c`/`amdahl.php` bound what a backend swap could buy through the PHP boundary; `cmp.c` runs ART against JudySL (needs libart cloned alongside — not vendored, and not built by CI) | `amdahl.c` warning-gated by `ci-smoke.sh` |

## Running them

```sh
./tools/ci-smoke.sh                 # n = 1000; seconds, not minutes
make -C tools/differential-fuzz smoke
```

The harnesses produce timings, so a figure taken from one needs an **idle
machine**. Load average alone is not a sufficient guard on a many-core box —
take [`bench-lock.sh`](bench-lock.sh) before the run and gate the output on
[`bench-stability.py`](bench-stability.py) afterwards; the incident that
produced both is written up in
[`../research/libjudy-modernization/o5p-harness/README.md`](../research/libjudy-modernization/o5p-harness/README.md).
The smoke and fuzz gates are correctness checks and do not care.

**The numbers these produced, and what they mean, live in
[`../research/README.md`](../research/README.md)** — including the corpus
shapes both benchmark harnesses share, the re-derived `JSLN` flatness result,
and the limits on each figure. Read that before quoting anything.

PHP-side and Python-side helpers (API-doc generation, the PHP benchmark
drivers, the lldb/gdb pretty-printers) are in [`../scripts/`](../scripts/); see
[CONTRIBUTING.md](../CONTRIBUTING.md#where-code-lives) for the full map.
