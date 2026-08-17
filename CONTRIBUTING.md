# Contributing to PHP Judy

Thanks for your interest in contributing! PHP Judy is a C extension, so the
workflow differs a bit from a pure-PHP project. This guide covers everything
needed to build, test, and submit changes.

## Prerequisites

- PHP 8.1+ with development headers (`php-dev` / `php-devel`, or a source build)
- The libJudy C library and headers:
  - Debian/Ubuntu: `apt-get install libjudy-dev`
  - Fedora/RHEL: `dnf install Judy-devel`
  - macOS: `brew install judy`
  - Windows: build libJudy from source (see the Windows section in [README.md](README.md))
- Standard C toolchain (`gcc`/`clang`, `make`, `autoconf`)

## Building from source

```sh
phpize
./configure --with-judy=/usr
make
```

To try the freshly-built extension without installing it:

```sh
php -d extension=modules/judy.so -r 'var_dump(judy_version());'
```

## Running the tests

The test suite is a standard `.phpt` suite under `tests/`:

```sh
make test TESTS=tests/ NO_INTERACTION=1 REPORT_EXIT_STATUS=1
```

A failing test leaves `tests/<name>.diff` / `.out` / `.exp` files behind for
inspection. Please add a `.phpt` test for every behavior change or bug fix —
regression tests are what keep a C extension safe to evolve.

### Internal consistency assertions

The four `*_HASH` / `*_ADAPTIVE` types keep the key set in a JudySL
`key_index` and the values in a separate store. A path that updates one and
not the other leaves two valid pointers holding different answers: no crash,
no leak, nothing valgrind can see. When touching a write, mutate or delete
path on those types, rebuild with the assertions on and run the suite:

```sh
phpize --clean && phpize
./configure --with-judy=/usr --enable-judy-debug-mirror
make
make test TESTS=tests/ NO_INTERACTION=1 REPORT_EXIT_STATUS=1
```

The checker walks both stores at object teardown and after `clone`, and
aborts with a `MIRROR INVARIANT VIOLATED` banner naming the offending key.
Without the flag it compiles to nothing — the linked `judy.so` is
byte-identical either way — so this is a development and CI build, never a
release one. CI runs it as the `debug-mirror-assertions` job, which ends with
a negative control that deliberately breaks the invariant and requires the
abort, so the harness cannot rot into a no-op.

## Code conventions

- **Zero compiler warnings.** CI fails on any warning in the extension source
  files. Build with `make` and check the output before pushing.
- **Memory safety first.** Every error path must release what it acquired.
  When in doubt, test with valgrind:
  `USE_ZEND_ALLOC=0 valgrind php -d extension=modules/judy.so tests/<script>.php`
- Match the style of the surrounding code.

## API changes

The public API is defined in `Judy.stub.php`. If you change it:

1. Regenerate the arginfo header (see the build output / `php_judy.h` includes).
2. Regenerate `API.md`: `php scripts/generate-api-docs.php` — CI fails if
   `API.md` is stale relative to the stub.

## Version bumps (maintainers)

The version string must change in lockstep in `php_judy.h`
(`PHP_JUDY_VERSION`) and `package.xml` (release + api version). CI validates
they match, and the release workflow validates the git tag against both.
See the [Releasing section in README.md](README.md#releasing) for the full
release checklist.

## Benchmarks

Benchmark scripts live in `examples/benchmarks/`. Benchmarks are **advisory** —
they never gate a merge.

CI compares each PR against the previous release, installed onto the same runner
via `pie`. The comparison is driven by `scripts/bench-compare.php`, which
interleaves the two extension builds per benchmark group (A/B/A/B) and repeats
the pair over several rounds in ABBA order. Each row in the report is the median
of the paired current/baseline ratios with a 95% percentile-bootstrap CI, and is
flagged only when the whole drift-adjusted CI clears ±10%. Running the two
suites back to back instead put the arms minutes apart, so runner drift showed
up as a page of regressions on code the diff could not reach (issue #87).

Reading the report:

- **`~same`** — either the difference is inside ±10%, or the CI straddles the
  threshold, i.e. there is no measured separation.
- **`unstable`** — the benchmark's own arms scattered by more than the effect
  being claimed, so it was not evaluated. Expect a few on a busy runner.
- **"Comparison contaminated"** — the run-wide median delta moved past ±5%,
  meaning the whole suite shifted together. That is a slower runner, not slower
  code; individual flags are suppressed and the job should be re-run.

To reproduce a comparison locally:

```sh
php scripts/bench-compare.php \
    --baseline-so /path/to/previous/judy.so \
    --current-so "$PWD/modules/judy.so" \
    --size 300000 --iterations 3 --rounds 5
```

If your change legitimately shifts performance, mention it in the PR
description; do not update `baselines/latest.json` in a feature PR.

## Submitting a pull request

1. Fork and create a topic branch off `main`.
2. Make your change with tests.
3. Ensure `make test` passes and the build is warning-free.
4. Open a PR with a clear description of what changed and why.

For larger changes, please open an issue first to discuss the approach.
