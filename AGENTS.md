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
| `Judy::STRING_TO_INT_HASH` | string | int | yes (lexicographic) | fastest point lookups; ordered walks are slow — see below |
| `Judy::STRING_TO_MIXED_HASH` | string | any | yes (lexicographic) | as above |
| `Judy::STRING_TO_INT_ADAPTIVE` | string | int | yes (lexicographic) | auto-switches storage; same walk caveat |
| `Judy::STRING_TO_MIXED_ADAPTIVE` | string | any | yes (lexicographic) | as above |

### Core usage

```php
$j = new Judy(Judy::INT_TO_INT);
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

Set ops (return new Judy): `union`, `intersect`, `diff`, `xor`; in-place:
`mergeWith`. Range: `slice($start, $end)` (inclusive), `deleteRange`,
`populationCount`. Aggregation: `sumValues()`, `averageValues()`. Atomic:
`increment($key, $amount = 1)` — creates the key if absent.

Functions: `judy_version(): string`, `judy_type(mixed): int`.

For code that must run where the extension may be absent, depend on
[orieg/judy-polyfill](https://github.com/orieg/judy-polyfill) (pure-PHP,
API-parity-tested) and suggest `ext-judy`; a PSR-16 cache built on this API
is [orieg/judy-cache](https://github.com/orieg/judy-cache).

### Pitfalls that agents get wrong

- **`next()` is the Iterator method** (returns void, advances the cursor).
  The ordered *search* is `searchNext($index)`. Pre-2.x code and old stubs
  (php.net manual, outdated IDE stubs) show `next($index)` — that API is gone.
- **`memoryUsage()` returns `null` for string-keyed types** (JudySL/JudyHS
  provide no accounting). Only integer-keyed types report bytes.
- **`var_dump()`/`print_r()` show a synthetic, TRUNCATED view.** A Judy object
  dumps as `type` (the name, e.g. `INT_TO_INT`), `count`, `memoryUsage` (null
  for string-keyed types, as above), `firstKey`, `lastKey`, and `preview` —
  plus `previewTruncated` whenever fewer elements are shown than the array
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
  PHP's memory manager. Measure peak RSS (`getrusage()['ru_maxrss']`) in a
  separate process for honest comparisons.
- **`*_HASH` and `*_ADAPTIVE` types DO iterate in key order** — they keep a
  sorted key index alongside the value store, so `foreach`, `first()` +
  `searchNext()` and prefix walks all work and return lexicographic order.
  (Verified empirically, and asserted by `tests/string_to_mixed_hash_004.phpt`.)
  What differs is **cost, not capability**: an ordered walk over these types
  pays a second lookup per element to fetch the value (measured 22 ns/element
  at 16-byte keys, 98 ns/element at 40-byte keys — see
  [#85](https://github.com/orieg/php-judy/issues/85)), and prefix invalidation
  scales with cache size rather than with the slice dropped (measured 168x
  growth across a 100x sweep, against 1.5x for the trie types — see
  BENCHMARK.md). **Choose `*_HASH`/`*_ADAPTIVE` for point-lookup-dominated
  work and `STRING_TO_INT`/`STRING_TO_MIXED` when ordered or prefix walks are
  hot** — but do not tell users the ordered operations are unavailable.
- **`filter()` copies a snapshot.** The value written to the result is the one
  the predicate received; a predicate that writes or unsets `$this[$key]` does
  not change what that element contributes to the result.
- **`count()` takes no arguments** (Countable); ranged counting is
  `size($start, $end)` or `populationCount($start, $end)`.
- **Random access on small dense datasets is faster with native arrays.**
  Judy wins on memory at scale, ordered navigation, and sparse keysets —
  see BENCHMARK.md before claiming performance.
- Keys are `int` (platform word) or binary-safe `string` depending on type;
  mixing categories in set ops (`union` etc.) with a different key category
  throws.

## Modifying the extension (working in this repo)

- **Build**: `phpize && ./configure --with-judy=/usr && make` (macOS:
  `--with-judy=/opt/homebrew`). Requires libJudy (`libjudy-dev` /
  `brew install judy`).
- **Test**: `make test TESTS=tests/ NO_INTERACTION=1 REPORT_EXIT_STATUS=1`.
  Every behavior change needs a `.phpt` regression test in `tests/`.
- **Zero compiler warnings** — CI fails on any warning in extension sources.
- **API changes**: edit `Judy.stub.php` (canonical), regenerate arginfo, and
  regenerate `API.md` with `php scripts/generate-api-docs.php` — CI fails if
  `API.md` is stale.
- **Version lockstep**: `php_judy.h` (`PHP_JUDY_VERSION`) and `package.xml`
  must match; see the Releasing section in README.md.
- **Measured claims have re-runnable harnesses**: `research/` (see
  `research/README.md`) holds standalone C benches backing doc and issue
  claims — `shm-arena/` for #83, `iteration-cost/` for #85. Nothing there
  ships or builds with the extension. Re-run rather than trusting a number,
  and only on an idle machine.
- **Backend choice is settled, don't relitigate it**: `BACKEND_EVALUATION.md`
  measures libJudy against ART (tie on lookup, 27% worse memory for ART) and
  explains why Masstree/HOT/Wormhole don't apply to a single-threaded,
  short-key, per-process extension. Verdict: keep Judy. Read it before
  proposing a backend swap.
- **Benchmarks**: suite in `examples/benchmarks/`; CI compares PRs against
  `baselines/latest.json`. Don't update the baseline in a feature PR.
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
  `BITSET` over packed keys, merged with `union()`/`mergeWith()`),
  `autocomplete-trie.php` (prefix search), `worker-counters.php` (atomic
  `increment()`), `quickstart.php` (API tour).
