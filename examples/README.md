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
| [autocomplete-trie.php](autocomplete-trie.php) | Prefix search / autocomplete via ordered keys — `first()` + `searchNext()` | `STRING_TO_MIXED` |
| [worker-counters.php](worker-counters.php) | Metrics accumulators in long-running workers with atomic `increment()` | `STRING_TO_INT_HASH`, `INT_TO_INT` |
| [ip-range-lookup.php](ip-range-lookup.php) | Floor lookup (`last()`) over range tables — IPs, tariffs, time buckets | `INT_TO_MIXED` |
| [sliding-window-rate-limit.php](sliding-window-rate-limit.php) | Sliding-window rate limiting / rolling metrics — `deleteRange()` expiry that visits only aged-out buckets | `INT_TO_INT` |
| [prefix-invalidation.php](prefix-invalidation.php) | Namespace invalidation (`user:123:*`) walking only the matching key slice, with a keys-visited comparison against a hash table | `STRING_TO_MIXED` |
| [coverage-index.php](coverage-index.php) | Line-coverage index (`file -> line -> tests`) as one BITSET over packed keys: interning, `union()`/`mergeWith()` merge of per-worker indexes, range-walk queries, honest peak-RSS comparison against the nested-array shape | `BITSET`, `STRING_TO_INT_HASH`, `INT_TO_MIXED` |

## Choosing a type

- **Integer keys**: `BITSET` (presence only), `INT_TO_INT` (int values),
  `INT_TO_PACKED` (packed ints), `INT_TO_MIXED` (any values)
- **String keys, ordered** (prefix/range walks): `STRING_TO_INT`, `STRING_TO_MIXED`
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
