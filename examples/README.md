# PHP Judy Examples

Runnable demos of the patterns Judy arrays are good at. Each script is
self-contained:

```sh
php examples/quickstart.php
```

## Demos

| Script | Pattern | Judy type used |
| ------ | ------- | -------------- |
| [quickstart.php](quickstart.php) | The essentials in one file: array access, iteration, navigation, bulk ops | `INT_TO_INT`, `STRING_TO_MIXED` |
| [dedup-large-stream.php](dedup-large-stream.php) | Membership "seen set" over millions of keys, with honest peak-RSS comparison of array vs exact-key Judy vs hashed-fingerprint BITSET | `STRING_TO_INT_HASH`, `BITSET` |
| [autocomplete-trie.php](autocomplete-trie.php) | Prefix search / autocomplete via ordered keys — the `first()` + `searchNext()` walk, which is the right shape when a `$limit` lets it stop early | `STRING_TO_MIXED` |
| [symbol-table-prefix.php](symbol-table-prefix.php) | Symbol table keyed by fully-qualified class name, queried by namespace (`App\Domain\*`) for LSP completion, namespace-scoped analysis rules and PHPUnit `--filter`: deriving inclusive key bounds from a prefix binary-safely (carry, unbounded and over-reach cases, all asserted), then reading the slice with one `keys`/`values`/`toArray($lo, $hi)` call, with keys-visited and PHP→C-crossing counts against a hash-table scan and against the per-element walk | `STRING_TO_MIXED`, `STRING_TO_MIXED_HASH` |
| [worker-counters.php](worker-counters.php) | Metrics accumulators in long-running workers with atomic `increment()` | `STRING_TO_INT_HASH`, `INT_TO_INT` |
| [ip-range-lookup.php](ip-range-lookup.php) | Floor lookup (`last()`) over range tables — IPs, tariffs, time buckets | `INT_TO_MIXED` |
| [sliding-window-rate-limit.php](sliding-window-rate-limit.php) | Sliding-window rate limiting / rolling metrics — `deleteRange()` expiry that visits only aged-out buckets | `INT_TO_INT` |
| [cache-ttl-pruning.php](cache-ttl-pruning.php) | High-throughput cache with native TTL timestamps and in-C batch eviction via `pruneExpired()` | `STRING_TO_ENTRY` |
| [prefix-invalidation.php](prefix-invalidation.php) | Namespace invalidation (`user:123:*`) walking only the matching key slice, with a keys-visited comparison against a hash table | `STRING_TO_MIXED` |
| [coverage-index.php](coverage-index.php) | Line-coverage index (`file -> line -> tests`) as one BITSET over packed keys, plus test-impact selection ("which tests must this diff run?"): interning, `union()`/`mergeWith()` merge of per-worker indexes, range-walk queries, sound escalation for changed lines with no recorded coverage, and an honest peak-RSS **and selection wall-time** comparison against the nested-array shape — with selection written both as a per-id `first()`/`searchNext()` walk and as one bulk `keys($lo, $hi)` range read, the primitive this example's first draft showed was missing ([#96](https://github.com/orieg/php-judy/issues/96)) | `BITSET`, `STRING_TO_INT_HASH`, `INT_TO_MIXED` |

## Choosing a type

- **Integer keys**: `BITSET` (presence only), `INT_TO_INT` (int values),
  `INT_TO_PACKED` (packed ints), `INT_TO_MIXED` (any values)
- **String keys, ordered** (prefix/range walks): `STRING_TO_INT`, `STRING_TO_MIXED`
- **String keys, cache & TTL workloads**: `STRING_TO_ENTRY` (values with TTL timestamps and native in-C `pruneExpired()`)
- **String keys, fastest point lookups** (no ordering): `STRING_TO_INT_HASH`,
  `STRING_TO_MIXED_HASH`
- **String keys, mixed workloads**: `STRING_TO_INT_ADAPTIVE`, `STRING_TO_MIXED_ADAPTIVE`

See [BENCHMARK.md](../BENCHMARK.md) for measured numbers and a full decision
guide, and [API.md](../API.md) for the complete API reference.

## Benchmarks

The benchmark suite behind BENCHMARK.md lives in [benchmarks/](benchmarks/).
Entry points:

```sh
php examples/benchmarks/run-benchmarks.php
php examples/benchmarks/run_comprehensive_benchmarks.php

# Judy vs APCu / SplFixedArray / sorted arrays, rather than vs a PHP array
php examples/benchmarks/judy-bench-alternatives.php
```

CI runs these against the committed baseline in `baselines/latest.json` to
catch performance regressions.
