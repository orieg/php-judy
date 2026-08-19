# Contributing to PHP Judy

Thanks for your interest in contributing! PHP Judy is a C extension, so the
workflow differs a bit from a pure-PHP project. This guide covers everything
needed to build, test, and submit changes.

## Prerequisites

- PHP 8.1+ with development headers (`php-dev` / `php-devel`, or a source build)
- Standard C toolchain (`gcc`/`clang`, `make`, `autoconf`)

**No system libJudy is needed.** The library is vendored under `libjudy/`
(Judy-1.0.5 plus the patch series in [libjudy/PATCHES.md](libjudy/PATCHES.md))
and compiled straight into the extension by default. The vendored units get
their own compile flags — `-O2 -fno-lto -fno-unroll-loops -DJU_64BIT`, plus
`-mpopcnt` where the compiler accepts it — attached per source file, so the
extension's global `-O3 -flto -funroll-loops` never reaches them. That
isolation is load-bearing for correctness, not a style preference; see the
warning below. The bundled build requires a 64-bit target: `configure` refuses
otherwise and points at `--with-judy=DIR`.

## Where code lives

Every kind of file in this repo has exactly one home. Before adding a
directory or a top-level file, find its row here — the point of the table is
that a new "somewhere to put this" is almost never needed.

| Content | Home | Ships in the PECL package? |
| ------- | ---- | -------------------------- |
| Extension sources and headers | repo root (`php_judy.c`, `judy_*.c/h`, `Judy.stub.php`, `Judy_arginfo.h`, `config.m4`, `config.w32`) | **yes** |
| Vendored third-party C | `libjudy/` — pristine upstream plus the ledgered patch series in [libjudy/PATCHES.md](libjudy/PATCHES.md) | **yes** |
| Behaviour tests | `tests/` — one `.phpt` per behaviour change, plus any `.inc` fixtures they drive | **yes** |
| Runnable user-facing demos | `examples/` (and `examples/benchmarks/` for the PHP benchmark suite) | **yes** |
| User and maintainer docs | repo-root `*.md` (`README.md`, `API.md`, `AGENTS.md`, `BENCHMARK.md`, …) | **yes** |
| Developer tooling: C harnesses, the differential fuzzer, CI helper scripts | `tools/` | no |
| PHP and Python helper scripts run by hand or by CI | `scripts/` | no |
| Evidence records: findings, pre-registrations, result dumps, closed spikes | `research/` | no |
| Benchmark baseline the CI gate compares against | `baselines/` | no |

Two rules keep the split from eroding:

- **Permanence decides the home, not provenance.** Something CI runs, or that
  a future contributor would re-run, is tooling and belongs in `tools/` even
  if it was written for one investigation. `research/` holds what a
  measurement *found*; `tools/` holds what produced it. This is why
  `ci-smoke.sh`, the benchmark harnesses and the differential fuzzer moved out
  of `research/`: a CI gate named after a research folder invites someone to
  delete it as stale.
- **Anything not shipped stays not shipped.** `package.xml` lists every file
  in the tarball, and the `validate-pecl` job asserts both directions:
  everything tracked under `tests/` and `libjudy/` must be listed, and
  [`tools/check-package-contents.sh`](tools/check-package-contents.sh) rejects
  a tarball that carries any `tools/` or `research/` path. Adding a shipped
  file means adding its `<file>` entry; adding an unshipped one means adding
  nothing.

Ambiguous case, recorded rather than silently decided: `research/shm-arena/`
is a closed feasibility spike (issue #83, closed not planned) whose C is still
compiled by `tools/ci-smoke.sh` as a rot guard. It stayed a record because
nobody re-runs it; the gate reaches into `research/` for it deliberately.

## Building from source

```sh
phpize
./configure
make
```

To try the freshly-built extension without installing it:

```sh
php -d extension=modules/judy.so -r 'var_dump(judy_version());'
```

### Building against a system libJudy instead

`--with-judy=DIR` takes the install prefix of a system libJudy, links against
it dynamically, and compiles nothing under `libjudy/`. CI covers this mode too.
Packages: `apt-get install libjudy-dev` (Debian/Ubuntu),
`dnf install Judy-devel` (Fedora/RHEL), `brew install judy` (macOS).

```sh
phpize
./configure --with-judy=/usr
make
```

**In that mode the flags of the library you link matter, and no flag recipe is
trustworthy.** Stock libJudy 1.0.5 writes up to 15 bytes into an 8-byte
`jp_1Index` field when a Judy1 leaf splays into immediates; a compiler that
exploits the out-of-bounds write truncates the copy, which **silently loses
`Judy::BITSET` keys**: `count()` over-reports while iteration and `isset()`
under-report. Measured, gcc 13/14 trigger it at `-O2 -funroll-loops` and gcc 15
at `-O3`, so "just build it at `-O2`" is not a guarantee. Debian, Ubuntu and
Fedora ship the widening patch; Homebrew ships stock sources but builds with
clang, which does not exploit it. Trust the runtime detector rather than any
flag list: `tests/bitset_immed_cascade_integrity_001.phpt` fails at `make test`
time on a miscompiled library — if it fails, the linked libJudy is the problem,
not this extension, and the bundled default is the way out. Full analysis:
[#131](https://github.com/orieg/php-judy/issues/131). The bundled tree is
immune on both counts: the field is widened in-tree (patch P1) and the flags
are pinned by `config.m4`.

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
no leak, nothing valgrind can see. `STRING_TO_INT_HASH` and long-keyed
`STRING_TO_INT_ADAPTIVE` go further *when the instance was constructed with
`optimizeIteration`*: they mirror the payload itself into the `key_index` slot
so ordered traversal need not look the value up a second time — see
`JUDY_MIRRORS_PAYLOAD` in `php_judy.h` — which makes a stale value a third way
for the two to disagree, and a mirror written on an instance that did not ask
for one a fourth. When touching a write, mutate or delete path on those types,
rebuild with the assertions on and run the suite:

```sh
phpize --clean && phpize
./configure --enable-judy-debug-mirror
make
make test TESTS=tests/ NO_INTERACTION=1 REPORT_EXIT_STATUS=1
```

The checker walks both stores at object teardown and after `clone`, and
aborts with a `MIRROR INVARIANT VIOLATED` banner naming the offending key.
Without the flag it compiles to nothing — the linked `judy.so` is
byte-identical either way — so this is a development and CI build, never a
release one. CI runs it as the `debug-mirror-assertions` job — which doubles as
the permanent guard for `--with-judy=DIR`, deliberately building against a
system libJudy so that mode of `config.m4` stays exercised — and it ends with
three negative controls — one deletes the counter bump, one deletes the payload
mirror write on an opted-in instance, one removes the gate so a
default-constructed instance mirrors anyway — and requires the abort in all
three, so the harness cannot rot into a no-op. The second control also re-runs
the same broken build against a default-constructed array and requires it to
*succeed*, which is what makes "optimizeIteration defaults to off" a tested
property rather than an intention.

One limit the checker cannot close: JudyHS exposes no enumeration primitive
(`JHSI`/`JHSG`/`JHSD`/`JHSFA` is the whole API), so both the existence check
and the payload comparison are driven from `key_index` outwards. A value-store
entry that `key_index` does not list is reachable only through the population
counter or as a valgrind leak.

### Building against a debug PHP

A PHP built with `--enable-debug` compiles in cross-checks that a release build
drops, among them the arginfo checks: what an internal function actually
returns, and how it parses its parameters, are compared against what its
arginfo declares. They catch a class of bug nothing else here sees —
`offsetSet()`/`offsetUnset()` were declared `IS_VOID` while returning a bool,
which aborts *every* call under such a PHP, and the whole existing test suite
passed anyway.

`shivammathur/setup-php` ships no debug builds, so this means compiling PHP.
From a PHP source tree:

```sh
./configure --enable-debug --disable-all --enable-cli --without-pear \
  --prefix="$HOME/php-debug"
make -j"$(nproc)" && make install
```

`--disable-all` is enough for this suite: `json` and `pcre` cannot be disabled
and are the only extensions the `.phpt` files use. Then build the extension
against it — a phpize build inherits `PHP_DEBUG` from the installed PHP, but
pass `--enable-debug` explicitly so the intent does not rest on inheritance:

```sh
phpize --clean && "$HOME/php-debug/bin/phpize"
./configure --with-php-config="$HOME/php-debug/bin/php-config" \
  --enable-debug
make
make test TESTS=tests/ NO_INTERACTION=1 REPORT_EXIT_STATUS=1
```

Check the PHP you built really is a debug one before trusting a clean run:
`php -r 'var_dump(PHP_DEBUG);'` must print `bool(true)` and `php -i` must say
`Debug Build => yes`. Note also that the extension only compiles against such a
PHP at all because `config.m4` accepts `PHP_DEBUG=1` as well as `"yes"` —
PHP normalises the value before `config.m4` runs, and the production branch
adds `-DNDEBUG`, which `Zend/zend_portability.h` rejects with a hard `#error`.

CI runs this as the `debug-php-assertions` job, which compiles the PHP once and
caches it on its version. One test is excluded there for now:
`debug_info_empty_001.phpt` calls `new Judy()`, and `Judy::__construct`
declares `$type` required in its arginfo while returning early without calling
`zend_parse_parameters` at zero arguments, which a debug PHP rejects with
`Arginfo / zpp mismatch during call of Judy::__construct()`. The job's last
step re-runs that one test and requires it to keep failing *for that reason*,
so the exclusion turns CI red the moment the constructor is fixed rather than
quietly outliving the bug.

## Debugging the extension itself (lldb)

This is the C-side story — a crash, a core dump, or a stop inside `php_judy.c`
where you are looking at a raw `judy_object`. (What a *PHP user* sees in
`var_dump()`, Xdebug or an IDE variable pane is a different thing entirely: the
`get_debug_info` handler, described in AGENTS.md.)

**The default build is not debuggable.** `phpize && ./configure && make`
inherits PHP's own `CFLAGS`, which on a typical distro or Homebrew PHP include
`-O3 -DNDEBUG -flto`. Under `-flto` clang emits a single debug-map entry
pointing at a temporary `/tmp/lto.o` that is gone by the time you debug, so
lldb has **no type information and no locals at all** for the extension — a
breakpoint hits and `frame variable intern` finds nothing. Rebuild first:

```sh
make clean && make EXTRA_CFLAGS="-g -O0 -fno-lto"
```

`EXTRA_CFLAGS` lands after `CFLAGS` on the compile line, so it wins. Confirm
with `nm -pa modules/judy.so | grep OSO`: you want one entry per object file
under `.libs/`, not a single `/tmp/lto.o`. Note this only re-flags the
extension's own sources — the vendored `libjudy/` units carry their per-source
flags *after* `EXTRA_CFLAGS`, so they stay at `-O2 -fno-lto` whatever you pass.
That is deliberate (a debug rebuild must not be able to reintroduce the #131
miscompile); to step through libJudy itself, edit `judy_vendor_cflags` in
`config.m4` for the duration and re-run `./configure`.

Then load the printers:

```
(lldb) command script import scripts/judy_lldb.py
```

They give `judy_object`, `judy_iterator` and `judy_packed_value` one-line
summaries, so plain `frame variable` is readable:

```
(judy_object *) intern = 0x104405320 Judy STRING_TO_INT_HASH count=3 \
    [string_keyed hash_keyed mirror_payload next_empty_is_valid iterator_initialized]
```

and a `judy` command for the full breakdown — the type as its name, the element
counter, every packed flag bitfield decoded, the storage roots labelled with
which libJudy flavour each one is *for this type*, and the iterator/cursor
state:

```
(lldb) judy intern
judy_object @ 0x104405320
  type              8 = STRING_TO_INT_HASH   [names from debug info (enum judy_type)]
  counter           3 element(s)
  ...
  storage roots (Pvoid_t; contents opaque — see the header note)
    array      0x788c0d000    JudyHS VALUE STORE — key -> zend_long, O(1) point lookup, unordered
    key_index  0x7890fd1d0    JudySL key index — sorted keys; payload slot MIRRORED (optimizeIteration on)
    hs_array   NULL           unused
  iterator / cursor state (Iterator methods; foreach uses judy_iterator)
    iterator_key     STRING(len=12) "session:beef"
    iterator_data    LONG 22
    next_empty       0   cached, usable
    key_scratch      0x1044bb000   -> "session:beef"   (live cursor key)
```

`judy` takes any variable path — `judy intern`, `judy object` (a `zend_object*`
is rebased through `offsetof(judy_object, std)` the way `php_judy_object()`
does), `judy it->intern.data` — and defaults to `intern` in the current frame.
It also cross-checks the six type-derived flags against `->type` and shouts if
they disagree, which is what a corrupted or half-constructed object looks like.

Two things to know:

- **The type names come from the `judy_type` enum in the debug info**, not from
  a table in the script, so they cannot drift from `judy_type_name()` in
  `php_judy.c`. There is a literal fallback for builds whose debug info lacks
  the enum.
- **It does not walk the Judy tree, deliberately.** libJudy's node layout is
  internal and version-dependent; a printer that decoded it by guesswork would
  be confidently wrong against the next libJudy, and a wrong element listing is
  worse than none when you are already chasing a corruption. Storage roots are
  printed as pointers with their role; population comes from `intern->counter`,
  which the extension maintains itself. To see elements, use the PHP side.

Nothing is evaluated in the inferior — every field is a direct memory read — so
the printers also work on a core dump and at a breakpoint in a crash handler.

Two gotchas that are not the printers' fault:

- A breakpoint set by *function name* stops at the function's first line,
  before its locals are assigned, so `intern` there is uninitialised garbage.
  Set the breakpoint a line or two later (`breakpoint set -f php_judy.c -l
  <line>`) or `next` once first.
- Once a summary is installed, `p intern` shows the summary instead of the
  struct. `frame variable --raw intern` (lldb) or `print /r intern` (gdb) gets
  the unfiltered fields back.

### The same thing under gdb (Linux, valgrind)

`scripts/judy_gdb.py` is the gdb twin — same command, same output. Everything
php-judy-specific lives in `scripts/judy_debug_common.py`, which both
front-ends import, so the two cannot drift; the front-ends only know how to
read fields out of their own debugger's values.

```sh
gdb -ex 'source scripts/judy_gdb.py' --args php -n -d extension=modules/judy.so t.php
```

It composes with the valgrind recipe above, which is the main reason it exists:

```sh
valgrind --vgdb=yes --vgdb-error=0 php -n -d extension=modules/judy.so t.php
gdb -ex 'source scripts/judy_gdb.py' -ex 'target remote | vgdb' $(which php)
```

Because nothing is evaluated in the inferior, `judy intern` works unchanged
over the vgdb remote and on a core dump (`gdb php core`).

## Modifying the bundled libJudy

`libjudy/` is vendored upstream code under **LGPL-2.1-or-later** (the extension
itself is PHP-3.01 — see [THIRD-PARTY.md](THIRD-PARTY.md)). Changes there follow
a stricter discipline than the rest of the tree, because a diff against the
pristine import has to stay readable:

- **One patch = one commit**, diffable against the pristine import commit.
- **One row in [libjudy/PATCHES.md](libjudy/PATCHES.md)** per patch: what
  changed, which files, why, when, and the tracking issue.
- **A per-file change notice** at the top of every modified file — date plus a
  short summary. This is LGPL-2.1 §2(b)'s prominent-notice requirement, not a
  convention we can drop.
- **No reformatting, no drive-by cleanups.** php-judy *additions* (the build
  shims under `src/wrappers/`, the pre-generated tables, `JudyNoInline.c`)
  carry provenance headers instead of change notices.
- Adding or removing a vendored source file means updating `config.m4`,
  `config.w32` **and** `package.xml`.

Two CI jobs guard this code specifically: `differential-fuzz` runs the bundled
tree against exact `std::set`/`std::map` oracles at the shipped production
flags, with a planted #131-class defect every run as a negative control, and
`build-harnesses` re-runs the ASan/UBSan harness grid against the bundled
tree as well as a system library. The fuzzer lives under
[`tools/differential-fuzz/`](tools/differential-fuzz/); the grid is driven by
[`tools/ci-smoke.sh`](tools/ci-smoke.sh).

## Code conventions

- **Zero compiler warnings.** CI fails on any warning in the extension source
  files (`php_judy.c`, `judy_handlers.c`, `judy_arrayaccess.c`,
  `judy_iterator.c` and their headers). The vendored `libjudy/` sources are
  deliberately excluded — third-party code at `-Wall` warns copiously, and
  patch hygiene beats chasing upstream warnings. Build with `make` and check
  the output before pushing.
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
- **"Comparison contaminated"** — the pure-PHP control (which runs no judy
  code) moved past ±5%, meaning the runner itself changed speed under the
  measurement; individual flags are suppressed and the job should be re-run.
  A uniform shift of the judy benchmarks over a flat control is **not**
  contamination — that is a real build-wide change (e.g. a libJudy-wide win
  or regression) and the per-row verdicts stand.

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
