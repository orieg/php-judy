# CLAUDE.md — php-judy repo conventions

C extension for PHP 8.1+ wrapping libJudy. Agent-facing API reference and
pitfalls: [AGENTS.md](AGENTS.md). Human workflow: [CONTRIBUTING.md](CONTRIBUTING.md).

## Build & test

```sh
phpize && ./configure --with-judy=/opt/homebrew && make        # macOS
make test TESTS=tests/ NO_INTERACTION=1 REPORT_EXIT_STATUS=1
php -d extension=$PWD/modules/judy.so -r 'var_dump(judy_version());'
```

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
  or docs requires updating it (CI `validate-pecl` builds and installs the
  PECL package).
- **Benchmark baseline** (`baselines/latest.json`) is bumped only in
  dedicated commits/PRs, never inside feature PRs. CI benchmark deltas on
  shared runners are noisy — uniform "regressions" across untouched ops are
  contention noise; re-run before believing them.
- Commit style: `type(scope): description` (feat/fix/docs/refactor/chore/...).
