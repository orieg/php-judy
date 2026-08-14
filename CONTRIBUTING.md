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

Benchmark scripts live in `examples/benchmarks/`. CI compares each PR against the
committed baseline in `baselines/latest.json`. If your change legitimately
shifts performance, mention it in the PR description; do not update the
baseline in a feature PR.

## Submitting a pull request

1. Fork and create a topic branch off `main`.
2. Make your change with tests.
3. Ensure `make test` passes and the build is warning-free.
4. Open a PR with a clear description of what changed and why.

For larger changes, please open an issue first to discuss the approach.
