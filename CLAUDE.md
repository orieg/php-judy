# CLAUDE.md — php-judy repo conventions

C extension for PHP 8.1+ wrapping libJudy, which is **bundled** under
`libjudy/` and compiled in by default. Agent-facing API reference and
pitfalls: [AGENTS.md](AGENTS.md). Human workflow: [CONTRIBUTING.md](CONTRIBUTING.md).

## Build & test

```sh
phpize && ./configure && make          # bundled libJudy; no system lib needed
make test TESTS=tests/ NO_INTERACTION=1 REPORT_EXIT_STATUS=1
php -d extension=$PWD/modules/judy.so -r 'var_dump(judy_version());'
```

`--with-judy=DIR` (e.g. `/usr`, `/opt/homebrew`, or an [Expanse](https://github.com/orieg/expanse) compat prefix)
switches to linking a system libJudy or Expanse (`libexpanse`) and compiles nothing under `libjudy/`;
all modes are CI-tested.

Single test: `make test TESTS=tests/<name>.phpt`. Failures leave
`tests/<name>.diff` behind.

## Hard rules

- **Zero compiler warnings** in extension sources — CI fails otherwise.
- **Every behavior change ships a `.phpt` test.** Memory-safety fixes should
  be verified with `USE_ZEND_ALLOC=0 valgrind` where practical.
- **`Judy.stub.php` is the canonical API definition.** After changing it,
  regenerate arginfo and run `php scripts/generate-api-docs.php` (CI gates
  `API.md` freshness). Keep AGENTS.md's type table in sync for API changes.
- **A stub docblock edit does NOT reach `API.md` on its own.** The generator
  prefers the `description` in `scripts/api-metadata.php` and only falls back to
  the stub PHPDoc when there isn't one — and 33 methods have one. So prose meant
  for readers of `API.md` must be added *there*, not just to the stub. The
  freshness gate cannot catch this: regeneration reproduces the committed file
  byte-for-byte and `--check` passes, which is exactly how #116's `toArray()`
  coercion warning reached the stub and AGENTS.md but never `API.md`.
  **Verify `git diff --stat API.md` shows a change, rather than trusting the
  gate.**
- **Version lockstep**: `PHP_JUDY_VERSION` in `php_judy.h` == version in
  `package.xml`. Full release checklist: README.md "Releasing" section.
- **`package.xml` lists every shipped file** — adding/moving tests, examples,
  docs, or `libjudy/` sources requires updating it (CI `validate-pecl` builds
  and installs the PECL package).
- **The vendored `libjudy/` units carry their own compile flags**
  (`-O2 -fno-lto -fno-unroll-loops -DJU_64BIT`, plus `-mpopcnt` where the
  compiler accepts it) so the project's global `-O3 -flto -funroll-loops`
  never reaches them — that isolation is load-bearing for correctness
  ([#131](https://github.com/orieg/php-judy/issues/131)), not a preference.
  Every change to those sources needs an entry in `libjudy/PATCHES.md` and a
  per-file LGPL §2(b) change notice.
- **Benchmark baselines** (`baselines/latest.json`, `baselines/arm-ratios.json`)
  are bumped only in dedicated commits/PRs, never inside feature PRs. They are
  different instruments: `latest.json` is absolute ms for the release-over-release
  `bench-compare.php` run; `arm-ratios.json` is per-platform within-run arm ratios
  for the cross-platform gate (`bench-gate.php`). CI benchmark deltas on shared
  runners are noisy — uniform "regressions" across untouched ops are contention
  noise; re-run before believing them.
- Commit style: `type(scope): description` (feat/fix/docs/refactor/chore/...).
