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
| `Judy::STRING_TO_INT_HASH` | string | int | **no** | fastest point lookups |
| `Judy::STRING_TO_MIXED_HASH` | string | any | **no** | |
| `Judy::STRING_TO_INT_ADAPTIVE` | string | int | no | auto-switches storage |
| `Judy::STRING_TO_MIXED_ADAPTIVE` | string | any | no | |

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
- **Judy memory is invisible to `memory_get_usage()`** — it allocates outside
  PHP's memory manager. Measure peak RSS (`getrusage()['ru_maxrss']`) in a
  separate process for honest comparisons.
- **`*_HASH` types do not iterate in key order.** If you need ordered/prefix
  walks over string keys, use `STRING_TO_INT`/`STRING_TO_MIXED` (trie).
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
- **Benchmarks**: suite in `examples/benchmarks/`; CI compares PRs against
  `baselines/latest.json`. Don't update the baseline in a feature PR.
- **Runnable demos**: `examples/` (see `examples/README.md`).
