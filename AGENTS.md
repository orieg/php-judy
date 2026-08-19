# PHP Judy — Guide for AI Coding Agents

php-judy is a PHP extension (written in C) wrapping the Judy C library:
memory-efficient, ordered, sparse dynamic arrays for PHP 8.1+. This file is
the fast path to using or modifying it correctly. Canonical references:
[API.md](API.md) (complete generated API), [BENCHMARK.md](BENCHMARK.md)
(measured performance + decision guide), [CONTRIBUTING.md](CONTRIBUTING.md)
(build/test workflow).

## Using the extension (writing PHP code against it)

### Type constants — pick by key/value shape

| Constant | Keys | Values | Ordered? | Notes |
| -------- | ---- | ------ | -------- | ----- |
| `Judy::BITSET` | int | bool | yes | presence only; cheapest |
| `Judy::INT_TO_INT` | int | int | yes | counters, ID maps |
| `Judy::INT_TO_PACKED` | int | int | yes | packed value storage |
| `Judy::INT_TO_MIXED` | int | any | yes | |
| `Judy::STRING_TO_INT` | string | int | yes (lexicographic) | trie-based |
| `Judy::STRING_TO_MIXED` | string | any | yes (lexicographic) | trie-based |
| `Judy::STRING_TO_INT_HASH` | string | int | yes (lexicographic) | fastest point lookups; ordered walks are slow unless `optimizeIteration` — see below |
| `Judy::STRING_TO_MIXED_HASH` | string | any | yes (lexicographic) | as above, but cannot take `optimizeIteration` |
| `Judy::STRING_TO_INT_ADAPTIVE` | string | int | yes (lexicographic) | auto-switches storage; same walk caveat, takes `optimizeIteration` for keys ≥ 8 bytes |
| `Judy::STRING_TO_MIXED_ADAPTIVE` | string | any | yes (lexicographic) | as above, but cannot take `optimizeIteration` |

### Core usage

```php
$j = new Judy(Judy::INT_TO_INT);
$j = new Judy(Judy::STRING_TO_INT_HASH, optimizeIteration: true); // see pitfalls
$j[42] = 7;                    // ArrayAccess
isset($j[42]); unset($j[42]);
count($j);                     // Countable
foreach ($j as $k => $v) {}    // Iterator (key order for ordered types)
json_encode($j);               // JsonSerializable
```

Navigation (ordered types): `first($idx)` / `last($idx)` are **inclusive**
searches (>= / <=); `searchNext($idx)` / `prev($idx)` are **exclusive**.
Empty-slot variants: `firstEmpty` / `nextEmpty` / `lastEmpty` / `prevEmpty`
(integer-keyed types only).

Bulk (run in C, prefer over PHP loops): `toArray()`, `Judy::fromArray($type,
$arr)`, `putAll($arr)`, `getAll($keys)`, `keys()`, `values()`, `forEach($cb)`,
`filter($cb)`, `map($cb)`.

`keys`, `values`, `toArray` and `size` also take an inclusive range —
`keys($start = null, $end = null)` — where `null` leaves that side unbounded.
All key types are supported; string-keyed types require string bounds and
compare them lexicographically. **This is the primitive to reach for on a
bounded read**: it is one traversal writing straight into the PHP array, so
prefer it over `slice($lo, $hi)->keys()`, which copies a whole sub-Judy first
and then traverses that copy. To *count* a range rather than read it, use
`size($lo, $hi)`, which runs the same traversal without building anything —
never `count($j->keys($lo, $hi))`.

Set ops (return new Judy): `union`, `intersect`, `diff`, `xor`; in-place:
`mergeWith`. Range: `slice($start, $end)` (inclusive), `deleteRange`,
`populationCount`, `size`, `keys`/`values`/`toArray` with bounds. Aggregation:
`sumValues()`, `averageValues()`. Atomic: `increment($key, $amount = 1)` —
creates the key if absent.

**Every range here is a pair of inclusive keys, never an offset and a length.**
Read `slice(5, 10)` as `range(5, 10)`, not as `array_slice($a, 5, 10)` — it is
"keys 5 through 10", and it returns nothing if no key in that span is set. Both
operations exist and they are different: `byCount($n)` is the positional one
("the Nth element present"); everything above is key-space. Key-space is where
Judy is fast — a bound is a seek plus a walk, so a narrow range out of a huge
array costs the range, not the array. All range methods use `$start`/`$end`
parameter names, so named arguments are uniform across them.

Introspection: `getType(): int`, `isIterationOptimized(): bool`.

Functions: `judy_version(): string`, `judy_type(mixed): int`.

For code that must run where the extension may be absent, depend on
[orieg/judy-polyfill](https://github.com/orieg/judy-polyfill) (pure-PHP,
API-parity-tested) and suggest `ext-judy`; a PSR-16 cache built on this API
is [orieg/judy-cache](https://github.com/orieg/judy-cache).

### Pitfalls that agents get wrong

- **`next()` is the Iterator method** (returns void, advances the cursor).
  The ordered *search* is `searchNext($index)`. Pre-2.x code and old stubs
  (php.net manual, outdated IDE stubs) show `next($index)` — that API is gone.
- **`memoryUsage()` returns two different kinds of number.** Integer-keyed
  types report libJudy's EXACT total (`Judy1MemUsed`/`JudyLMemUsed`).
  String-keyed types report an APPROXIMATION the extension maintains itself,
  because JudySL/JudyHS expose no accounting: it counts payload only — stored
  key bytes (twice for the `_HASH` types, which hold each key in the value
  store and in the key index), one word per value slot, and the `zval` box per
  `_MIXED` value — and excludes everything libJudy allocates for its trie and
  hash nodes. It is a LOWER BOUND: useful for tracking growth within one array,
  wrong to compare against an integer-keyed array's exact figure. Both are
  O(1) and both return `0` for a new or emptied array. For the true
  string-keyed footprint measure peak RSS in a separate process, or Massif via
  `examples/benchmarks/judy-bench-memory.php`. Details: BENCHMARK.md
  "Understanding `Judy::memoryUsage()`".
- **`var_dump()`/`print_r()` show a synthetic, TRUNCATED view.** A Judy object
  dumps as `type` (the name, e.g. `INT_TO_INT`), `count`, `memoryUsage` (plus
  `memoryUsageIsApproximate => true` for string-keyed types, as above),
  `firstKey`, `lastKey`, and `preview` — plus
  `previewTruncated` whenever fewer elements are shown than the array
  holds. `preview` is capped at `judy.debug_preview_size` (default 16,
  `PHP_INI_ALL`, so `ini_set()` works mid-session); `0` disables the element
  preview and leaves metadata only, and negatives clamp to 0. The cap exists
  because a debug dump has to stay cheap enough to be safe at a breakpoint —
  Xdebug and the PhpStorm/VS Code variable panels read the same handler over
  DBGp, and serializing millions of elements there would hang the session.
  **Never read element counts off a dump**: `count` and `previewTruncated`
  carry the true total, `preview` is a sample. For the real contents use
  `toArray()`/`keys()`/`values()`, which are unaffected by the INI.
- **Judy memory is invisible to `memory_get_usage()`** — it allocates outside
  PHP's memory manager, and Xdebug's memory column inherits that blindness.
  Measure peak RSS (`getrusage()['ru_maxrss']`) in a separate process for
  honest comparisons; see "Debugging and profiling Judy code" below.
- **`*_HASH` and `*_ADAPTIVE` types DO iterate in key order** — they keep a
  sorted key index alongside the value store, so `foreach`, `first()` +
  `searchNext()` and prefix walks all work and return lexicographic order.
  (Verified empirically, and asserted by `tests/string_to_mixed_hash_004.phpt`.)
  What differs is **cost, not capability**: by default an ordered walk over
  these types pays a second lookup per element to fetch the value (measured 22
  ns/element at 16-byte keys, 98 ns/element at 40-byte keys — see
  [#85](https://github.com/orieg/php-judy/issues/85); removable on the two
  `_INT` types with `optimizeIteration`, see the next pitfall), and prefix
  invalidation
  scales with cache size rather than with the slice dropped (measured 168x
  growth across a 100x sweep, against 1.5x for the trie types — see
  BENCHMARK.md). **Choose `*_HASH`/`*_ADAPTIVE` for point-lookup-dominated
  work and `STRING_TO_INT`/`STRING_TO_MIXED` when ordered or prefix walks are
  hot** — but do not tell users the ordered operations are unavailable.
- **`optimizeIteration` is a trade, defaults to off, and is fixed at
  construction.** `new Judy($type, optimizeIteration: true)` (also
  `Judy::fromArray($type, $data, true)`) makes ordered traversal read each
  value out of the key index it is already walking instead of looking it up
  again — **but every write then has to update both.** Measured: `foreach`
  24-38% faster and `values()` 29-47% faster depending on key length, against
  8-20% slower on overwrite and on `increment()`, with the *worst* write
  penalty at the shortest keys, where the read win is smallest. Turn it on for
  a read-dominated cache; leave it off for anything counter-heavy —
  `increment()` is the headline reason not to turn it on blindly. It cannot be
  changed after construction, and it is inherited by every derived array
  (`clone`, `slice()`, `filter()`, `map()`, `union`/`intersect`/`diff`/`xor`,
  `__serialize`/`__unserialize`). **Only `STRING_TO_INT_HASH` and
  `STRING_TO_INT_ADAPTIVE` honour it** (ADAPTIVE only for keys of 8 bytes or
  more — shorter keys live in a JudyL that is already cheap to read). Every
  other type accepts the argument and silently ignores it, so generic code may
  pass it unconditionally; call `isIterationOptimized()` to find out what
  actually took effect rather than assuming the request was honoured.
- **`filter()` copies a snapshot.** The value written to the result is the one
  the predicate received; a predicate that writes or unsets `$this[$key]` does
  not change what that element contributes to the result.
- **`count()` takes no arguments** (Countable); ranged counting is
  `size($start, $end)` for every type, or `populationCount($start, $end)` for
  integer-keyed types only — it reads libJudy's population cache, which
  JudySL/JudyHS do not have, and throws on a string-keyed array. `size()` on a
  string-keyed range walks the key index instead: the cost of the range, not
  of the array.
- **`toArray()` coerces integer-looking string keys; `keys()` does not.** The
  result is a PHP array, which cannot hold the string key `"42"`, so a canonical
  decimal integer in `PHP_INT` range comes back as an `int` (`"42"`, `"-7"`),
  while `"07"`, `"-0"`, `" 42"`, `"4.0"` and out-of-range values stay strings.
  Round-tripping bites: `foreach ($j->toArray() as $k => $v) { unset($j[$k]); }`
  throws `TypeError` on a string-keyed array the moment one key is numeric,
  because the offset arrives as an `int`. Use `keys()` (always strings) when the
  key has to go back into the Judy, or cast with `(string)`. This is a PHP array
  limitation, not something the extension can fix — but it is silent until a
  numeric key shows up, so code that only ever saw `"user.3"`-shaped keys will
  pass its tests and fail in production.
- **A string upper bound is a bound, not a prefix match.** `keys('bl', 'bl')`
  does not return `blackcurrant` — `blackcurrant` sorts *after* `bl`. For a
  prefix sweep, bound with the prefix and its successor: `keys('bl', 'bm')`.
  Two details that bite on high-byte keys, both worked through and asserted
  in `examples/symbol-table-prefix.php`: the successor needs a **carry** when
  the prefix ends in `"\xff"` (and an all-`"\xff"` prefix has no successor at
  all — pass `null` for "to the end"), and because the upper bound is
  **inclusive** the range over-reaches by at most one key, the one spelled
  exactly like the bound (`'bm'` itself), which a read should drop. Appending
  `"\xff"` to the prefix instead is *not* equivalent — it silently omits any
  key carrying a `0xff` byte right after the prefix. Same rule for `slice()`
  and `deleteRange()`, except a delete cannot drop the over-reach afterwards.
- **Random access on small dense datasets is faster with native arrays.**
  Judy wins on memory at scale, ordered navigation, and sparse keysets —
  see BENCHMARK.md before claiming performance.
- Keys are `int` (platform word) or `string` depending on type; mixing
  categories in set ops (`union` etc.) with a different key category throws.
- **String keys are binary-safe for every byte EXCEPT `0x00`.** `0x80`,
  `0xFE`, `0xFF` and everything else store, compare and order as unsigned
  bytes on all six string-keyed types, which is what the prefix-successor
  arithmetic above relies on. A key containing an embedded NUL is **rejected
  with an exception on all six types** — `Judy … keys must not contain
  embedded null bytes` — on every method that takes a string key: `offsetSet`
  /`offsetGet`/`offsetExists`/`offsetUnset`, `increment()`, `fromArray()`,
  `putAll()`, `getAll()`, `slice()`, `deleteRange()`, `first()`/`last()`
  /`searchNext()`/`prev()`, and the `$start`/`$end` bounds of `keys()`,
  `values()`, `toArray()` and `size()`. The reason is structural, not a
  policy choice: every string-keyed type orders its keys through a JudySL
  trie (the two trie types store the value in it directly, the four
  `_HASH`/`_ADAPTIVE` types keep a JudySL key index beside their
  length-prefixed value store), and a JudySL index is a NUL-terminated C
  string. There is nothing to fall back on, so the key is refused rather
  than silently truncated at the NUL. **`pack()` output, raw digests,
  serialized payloads and packed tuples therefore need encoding** (base64,
  hex, or a NUL-free framing) before they can be used as keys. Values are
  unaffected — a `_MIXED` value may contain any bytes at all.
  (Before this landed, `STRING_TO_INT` and `STRING_TO_MIXED` truncated
  instead, so `"ab\0cd"` and `"ab"` collided and one value was lost with no
  signal; see [#117](https://github.com/orieg/php-judy/issues/117).)
- **Integer keys sort in UNSIGNED order, so negative keys come last.** An
  integer key is the full unsigned machine word: a negative PHP int is
  reinterpreted as its bit pattern, so `-1` addresses the maximum index and
  reads back as `-1`. Iteration, `keys()`, `first()`/`last()`, `slice()` and
  every range bound see `0, 1, …, PHP_INT_MAX, PHP_INT_MIN, …, -2, -1`. This
  is why `size(0, -1)` means "everything" — `-1` is the largest key, not the
  smallest. (Before 2.5.0 `$j[-1] = $v` appended instead of storing the key;
  see MIGRATION_2.5.0.md.)
- **`$j[] =` throws once the maximum index is occupied.** Append means "one
  past the highest key", so an array holding a key at `-1` has nowhere left to
  append and raises *"cannot append, the integer key space is exhausted"* —
  the same position PHP's own arrays take at `PHP_INT_MAX`. Note `PHP_INT_MAX`
  is not the ceiling: in unsigned order the next key is `PHP_INT_MIN`, which is
  free. If you mix negative keys with append on one array, write explicit keys.

### Debugging and profiling Judy code

- **Judy and Xdebug coexist; load order does not matter.** Judy is a module
  extension that registers a class and object handlers and does not hook the
  executor; Xdebug is a zend_extension that does. Verified empirically, not
  argued: `php:8.4-cli` with judy built from source + Xdebug 3.5.3 from PECL,
  both `extension_loaded()` true, `var_dump()` of a Judy object correct under
  `xdebug.mode=develop` (Xdebug overrides `var_dump()` and goes through the
  same `get_debug_info` handler), iteration correct, and swapping
  `-d extension=judy.so` / `-d zend_extension=xdebug.so` changes nothing.
- **Do NOT diagnose Judy memory with a profiler.** Xdebug's memory column is
  `memory_get_usage()` underneath, and Judy allocates outside PHP's memory
  manager. Measured: a function filling `STRING_TO_INT` with 50K keys traced at
  `xdebug.mode=trace` shows a 65,696-byte delta — PHP-side overhead only, none
  of the ~1.24 MB of trie it actually allocated — while the equivalent PHP
  array shows its full 4,941,520 bytes. Judy looks free exactly where a user is
  hunting a memory-limit crash. Use `memoryUsage()` (in-process; exact for
  integer-keyed types, an approximate lower bound for string-keyed ones) and
  peak RSS via `getrusage()['ru_maxrss']` measured in a **separate process**.
- **The signal profilers DO give is call counts.** `$j[$key]` dispatches
  straight to the extension's dimension handler, so it does not appear in a
  trace or cachegrind profile at all — the cost lands in the enclosing
  function. Explicit `$j->offsetGet($key)` calls do appear one per lookup
  (5,000 lookups = 5,000 entries) against a single entry for the bulk call.
  Either way the fix is the bulk API: `getAll()` 1.9x faster than individual
  lookups (int keys, 10K lookups over 100K elements) and `toArray()` 2.8x
  faster than a manual `foreach` (100K int-keyed; 3.1x string-keyed) — measured,
  BENCHMARK.md Tables 5 and 6. `forEach()`/`filter()`/`map()` are the same
  trade for callback loops.
- **What an IDE variable pane shows** is the `get_debug_info` output described
  in the `var_dump()` pitfall above: whole-array `count`/`firstKey`/`lastKey`,
  a `preview` sample bounded by `judy.debug_preview_size` (0 = metadata only),
  a `previewTruncated` note carrying the true total whenever the preview is
  short, and `memoryUsageIsApproximate` on the string-keyed types.

## Modifying the extension (working in this repo)

- **Build**: `phpize && ./configure && make`. libJudy is **bundled** under
  `libjudy/` and compiled straight into the extension by default — no system
  library, no `libjudy-dev`, nothing to download. `--with-judy=DIR` (`/usr`,
  `/opt/homebrew`) switches to dynamically linking a system libJudy and
  compiles nothing under `libjudy/`; both modes are CI-tested. The bundled
  build requires a 64-bit target (`configure` errors out otherwise, pointing
  at `--with-judy=DIR`).
- **Test**: `make test TESTS=tests/ NO_INTERACTION=1 REPORT_EXIT_STATUS=1`.
  Every behavior change needs a `.phpt` regression test in `tests/`.
- **Zero compiler warnings** — CI fails on any warning in extension sources.
- **The silent key-loss hazard is a SYSTEM-libJudy problem only.** Stock 1.0.5
  copies up to 15 bytes into an 8-byte `jp_1Index`; a compiler that exploits
  that out-of-bounds write truncates the copy and `Judy::BITSET` loses keys
  silently — `count()` over-reports while iteration and `isset()` under-report.
  The bundled tree cannot hit it: the field is widened in-tree (patch P1) and
  the vendored units are pinned to `-O2 -fno-lto -fno-unroll-loops`,
  isolated from the extension's own `-O3 -flto`. For a system library there is
  **no trustworthy flag recipe** — measured, gcc 13/14 trigger at
  `-O2 -funroll-loops` and gcc 15 at `-O3` — so the runtime detector is the
  only authority: `tests/bitset_immed_cascade_integrity_001.phpt` fails on a
  miscompiled library, and a failure there means that library needs rebuilding
  (or switching to the bundled default), not that the extension regressed. See
  [#131](https://github.com/orieg/php-judy/issues/131) and
  `libjudy/PATCHES.md`.
- **Changing the vendored libJudy** (`libjudy/src/**`): one patch = one commit,
  one row in `libjudy/PATCHES.md`, and a per-file LGPL-2.1 §2(b) change notice
  at the top of every modified file. Never reformat or clean up in passing — a
  diff against the pristine import must show only documented changes. The
  vendored units' compile flags live in `config.m4` and are load-bearing, not a
  preference. The extension is PHP-3.01; the bundled library is
  LGPL-2.1-or-later — see `THIRD-PARTY.md`.
- **API changes**: edit `Judy.stub.php` (canonical), regenerate arginfo, and
  regenerate `API.md` with `php scripts/generate-api-docs.php` — CI fails if
  `API.md` is stale.
- **Version lockstep**: `php_judy.h` (`PHP_JUDY_VERSION`) and `package.xml`
  must match; see the Releasing section in README.md.
- **Debugging the C side**: `scripts/judy_lldb.py` (lldb) and
  `scripts/judy_gdb.py` (gdb, composes with the valgrind recipe above) decode a
  raw `judy_object` — type NAME rather than the enum integer, element counter,
  every packed flag bitfield, which libJudy structure each `Pvoid_t` root is
  for that type (including the key_index/value-store split on
  `*_HASH`/`*_ADAPTIVE`), and iterator/cursor state. **The default build is not
  debuggable** — PHP's own CFLAGS carry `-O3 -flto`, which leaves the debugger
  with no types and no locals; rebuild with `make clean && make
  EXTRA_CFLAGS="-g -O0 -fno-lto"` first. They deliberately do NOT walk the Judy
  tree (libJudy's node layout is internal and version-dependent). Load
  instructions and the full rationale: CONTRIBUTING.md "Debugging the extension
  itself".
- **Measured claims have re-runnable harnesses**: `tools/` holds the
  standalone C benches backing doc and issue claims —
  `tools/iteration-cost/` for #85, `tools/write-probe-cost/` for #85 step B3,
  `tools/backend-comparison/` for BACKEND_EVALUATION.md — and
  `tools/differential-fuzz/` is the correctness fuzzer CI runs per PR. What
  they measured is written up in `research/README.md`; closed investigations
  keep their evidence under `research/` (`shm-arena/` for #83). Nothing in
  either tree ships or builds with the extension. Re-run rather than trusting
  a number, and only on an idle machine.
- **Backend choice is settled, don't relitigate it**: `BACKEND_EVALUATION.md`
  measures libJudy against ART (tie on lookup, 27% worse memory for ART) and
  explains why Masstree/HOT/Wormhole don't apply to a single-threaded,
  short-key, per-process extension. Verdict: keep Judy. Read it before
  proposing a backend swap. The follow-on question — *optimise or vendor
  libJudy itself* — is recorded in
  `research/libjudy-modernization/FINDINGS.md` (issue #113) and was executed as
  the vendoring in issue #142. Read it before proposing popcount, SIMD,
  prefetch, an arena, `-O3`/LTO/PGO, or a fork: most of those are measured
  negatives there, the ones that survived their gates are already applied in
  `libjudy/PATCHES.md`, and `-O3` on the vendored units is a correctness
  hazard rather than an optimization.
- **Benchmarks**: suite in `examples/benchmarks/`; CI compares PRs against
  `baselines/latest.json`. A separate cross-platform gate
  (`scripts/bench-gate.php`, weekly + on `libjudy/` changes) compares the
  bundled tree against a *pristine-static* arm S reconstructed from our own
  pre-patch commit, on Linux glibc, Alpine musl, macOS arm64 and Windows. It
  gates on within-run arm ratios, never on absolute times. Don't update
  `baselines/latest.json` or `baselines/arm-ratios.json` in a feature PR.
- **Verify you are testing the build you think you are.** If any ini file
  under `conf.d/` already loads `judy.so` (a Docker image built with
  `docker-php-ext-enable judy`, a system install, a PIE install), that copy
  wins and your `-d extension=/path/to/modules/judy.so` is **ignored** — the
  only signal is `Module "judy" is already loaded in Unknown on line 0`.
  Always use `PHP_INI_SCAN_DIR= php -d extension=...` (or `php -n -d ...`)
  when measuring or valgrinding a build you just compiled. `judy_version()`
  will not save you: the stale and fresh builds report the same version. This
  has already produced one wrong conclusion — a memory-safety fix was measured
  as ineffective while the container re-ran the pre-fix binary, and the
  isolated re-run reversed the result.
- **Runnable demos**: `examples/` (index + type-per-demo table in
  `examples/README.md`). Reach for these before writing a pattern from
  scratch — each is the idiomatic shape for its problem:
  `dedup-large-stream.php` (membership/seen-sets, `BITSET` memory story),
  `ip-range-lookup.php` (floor lookup via `last()` — CIDR, tariff bands),
  `sliding-window-rate-limit.php` (time-bucket expiry via `deleteRange()`),
  `prefix-invalidation.php` (namespace drop via `first()` + `searchNext()`),
  `coverage-index.php` (nested `file -> line -> ids` maps flattened into one
  `BITSET` over packed keys, merged with `union()`/`mergeWith()`, then queried
  for test-impact selection — the contiguous-block walk, and why an uncovered
  changed line must widen rather than select nothing),
  `symbol-table-prefix.php` (FQCN symbol table queried by namespace —
  deriving inclusive key bounds from a prefix binary-safely, then reading the
  slice with one `keys`/`values`/`toArray($lo, $hi)` call; counts keys visited
  and PHP→C crossings against a hash scan and against the per-element walk),
  `autocomplete-trie.php` (prefix search, and when the walk still beats a
  bounded read), `worker-counters.php` (atomic
  `increment()`), `quickstart.php` (API tour).
