# PHP Judy Performance Benchmarks

This document provides a comprehensive performance and memory usage comparison between the `php-judy` extension and PHP arrays, based on our extensive benchmarking suite.

## 🔬 **Judy's Core Design & Performance**

The Judy algorithm is a form of a trie or a radix tree, optimized for in-memory integer and string key-value storage. Unlike simple hash tables, Judy arrays are designed to be memory-efficient and to maintain sorted order. This is what gives them unique performance characteristics.

### **Memory Efficiency**
Judy's design uses a series of nodes that compress branches of the tree, which can lead to very low memory overhead, especially with dense key sets.

### **Sorted Traversal**
Because the keys are stored in a tree-like structure, iterating through them in sorted order is a native and highly performant operation. A hash table, by contrast, must first sort all keys before it can be traversed in order.

### **Locality of Reference**
For dense, sequential keys, Judy arrays have excellent cache performance (cache-friendly) because related keys are stored in a contiguous manner in memory.

### **Modern Algorithms and Benchmarking**
Modern data structures like Swiss tables (used in abseil and Folly) and Robin Hood hashing (used in C++ unordered_map) are highly optimized hash tables that are generally considered to be some of the fastest. They achieve their performance by minimizing cache misses and collisions.

**Random Access**: For random key lookups and insertions (the most common use case for a map or dictionary), highly-optimized hash tables will typically outperform Judy arrays. This is because they can find a key in near-constant time (O(1)), while Judy's lookup time is logarithmic with the number of bits in the key (O(logn) for a balanced trie).

**Benchmarks**: The most accurate performance metrics come from benchmarks that test specific real-world workloads, not just raw operations. Factors like key sparsity, key type (integer vs. string), and access patterns (random vs. sequential) can dramatically change the outcome. An algorithm that excels at one task might be slow at another.

### **The Key Difference: O(log n) vs O(1)**

**Native PHP Arrays (Hash Tables)**: PHP arrays are implemented as a highly-optimized hash table. A hash table is designed for constant-time average lookups, denoted as O(1). It works by calculating a hash value from the key, which directly points to the memory location of the value. This process is extremely fast and doesn't depend on the total number of elements in the array.

**Judy Arrays (Tries)**: Judy arrays are a type of trie or radix tree. To find a key, the algorithm must traverse down the tree, inspecting parts of the key at each node. This makes Judy's lookup time logarithmic, denoted as O(logn), where n is the number of bits in the key. While this is very efficient, it's inherently slower than the single-step lookup of a hash table for random access.

**Why this impacts performance**: The benchmark results clearly show the impact of this difference in random access patterns:

- **Random Lookups**: The benchmarks show Judy is 7.5x slower than PHP arrays for random access. This is because each lookup requires a traversal of the trie, which involves multiple pointer dereferences and memory jumps, while a hash table typically performs a single, fast lookup.

- **Sequential Access**: Judy performs much better in sequential access and range queries because its sorted trie structure is designed for this. When traversing in order, Judy benefits from cache locality, as it can access adjacent keys with minimal overhead. The performance gap for sequential access is only 4.2x slower, and for range queries, it's nearly competitive (1.1x slower).

**In short, the performance difference is not a flaw but a direct consequence of Judy's design trade-offs. It sacrifices some random access speed to achieve exceptional memory efficiency and fast ordered operations, which native PHP arrays do not provide.**

---

## 🖥️ **Benchmarking Environment**

- **Hardware**: Tests run on modern x86_64 systems with sufficient RAM to avoid memory pressure
- **Operating System**: Linux (Docker containers for consistency)
- **PHP Version**: 8.x with Judy extension 2.4.2 — the release these figures were
  measured on. They still describe **2.5.2**, carried forward by two
  release-over-release comparisons, each interleaved (5 groups x 5 rounds x 2
  arms, PHP 8.4.24, Linux x86_64) with a PHP-array control to catch runner
  contention:

  | Comparison | Run-wide median | Control | Regressions | Improvement |
  | --- | --- | --- | --- | --- |
  | 2.4.2 -> 2.5.0 | -0.04% | +0.36% | **0** | `adv.filter.int_to_int` -12.1% |
  | 2.5.0 -> 2.5.2 | +0.21% | -0.22% | **0** | `api.setop.union.string_to_int` -11.9% |

  So the figures below are representative rather than merely unrefuted. Two
  changes worth naming because they touch the write and merge paths and could
  plausibly have cost something: the embedded-NUL guard added in 2.5.1 (a
  `memchr` on every string-key write) and the `mergeWith` slot reuse in 2.5.2.
  **Neither shows a measurable regression**; the second shows up as the union
  improvement above, that set operation running through the merge machinery.

  What 2.5.x adds on top is measured separately in
  [The optimizeIteration mirror](#the-optimizeiteration-mirror-measured) and in
  the bounded-read benchmarks described there.
- **Test Methodology**: Multiple iterations with statistical analysis (min/max/median/percentiles)
- **Memory Measurement**: Using `memory_get_usage(true)` and `Judy::memoryUsage()`

*Note: Results may vary based on hardware, system load, and PHP configuration. All benchmarks use the same Docker environment for consistency.*

## 🎯 **Quick Decision Guide**

> Choosing between Judy and **APCu, `SplFixedArray`, or a sorted array** rather
> than a PHP array? Skip to [Versus the Alternatives](#versus-the-alternatives-apcu-splfixedarray-sorted-arrays).

> Evaluating Judy for a **PHP developer tool** — a test runner, a static
> analyser, a refactoring pass, a dependency manager? Several of those
> candidates were examined and rejected. The reasons are in
> [Judy in PHP Developer Tooling](#judy-in-php-developer-tooling).

**Use Judy Arrays When:**
- ✅ Memory is constrained (2-4x less memory usage)
- ✅ Large datasets (> 1M elements) where memory efficiency matters
- ✅ Sequential access patterns and ordered iteration
- ✅ Range queries and ordered operations
- ✅ String key random access with Hash or Adaptive types (O(1) avg lookup)

**Use PHP Arrays When:**
- ❌ Random access patterns with integer keys (2-9x faster than Judy trie types)
- ❌ Small datasets (< 100k elements)
- ❌ Performance-critical random operations on integer keys
- ❌ Memory is not a constraint

---

## 📊 **Comprehensive Performance Analysis**

Our benchmark suite tests multiple scenarios to provide realistic performance data:

### **Benchmark Scripts**
- `examples/benchmarks/benchmark_ordered_data.php` - Sequential, clustered, and random key patterns
- `examples/benchmarks/benchmark_range_queries.php` - Range queries and ordered operations
- `examples/benchmarks/benchmark_real_world_patterns.php` - Database, log, analytics patterns
- `examples/benchmarks/run_comprehensive_benchmarks.php` - Complete benchmark suite
- `examples/benchmarks/judy-bench-alternatives.php` - Judy vs APCu / SplFixedArray / sorted arrays (see [Versus the Alternatives](#versus-the-alternatives-apcu-splfixedarray-sorted-arrays))

### **Test Scenarios**
1. **Ordered Data Performance**: Sequential keys, clustered keys, random keys
2. **Range Query Performance**: Range operations, ordered iteration, navigation
3. **Real-world Patterns**: Database primary keys, log data, analytics, session data
4. **Memory Efficiency**: Memory usage across different patterns and sizes

---

## 📈 **Key Performance Findings**

### **Table 1: Memory Efficiency & Performance Trade-offs**

| Dataset Size | Memory Savings | Performance Impact | Best Use Case       | Recommendation                        |
| ------------ | -------------- | ------------------ | ------------------- | ------------------------------------- |
| **100k**     | 12.5x less     | 4.2x slower        | Small datasets      | ⚠️ Consider Judy if memory constrained |
| **500k**     | ~2.2x less     | ~2-3x slower       | Medium datasets     | ⚠️ Consider Judy                       |
| **1M**       | ~2.2x less     | ~3x slower         | Large datasets      | ✅ Use Judy                            |
| **10M**      | ~3.5x less     | ~3-9x slower       | Very large datasets | ✅ Use Judy                            |

**Key Insight**: Judy becomes more attractive as dataset size increases due to memory efficiency gains.

### **Table 2: Access Pattern Performance (100K elements)**

| Access Pattern        | Judy Performance | PHP Performance | Judy vs PHP  | Use Case                           |
| --------------------- | ---------------- | --------------- | ------------ | ---------------------------------- |
| **Random Access**     | 6.55ms           | 0.87ms          | 7.5x slower  | ❌ Avoid Judy                       |
| **Sequential Access** | 3.62ms           | 0.87ms          | 4.2x slower  | ⚠️ Consider Judy                    |
| **Judy Iterator**     | 20.13ms          | 1.79ms          | 11.2x slower | ⚠️ Consider Judy for large datasets |
| **Range Queries**     | ~3.2ms           | ~2.8ms          | 1.1x slower  | ✅ Judy strength                    |

**Key Insight**: Judy excels at range queries and sequential access. Iterator performance depends on dataset size - faster than sequential for large sparse datasets, slower for small sequential datasets.

**Note**: The 11.2x slower result is from a small (100K) sequential dataset where iterator overhead is significant. For large sparse datasets (>1M), iterators become competitive with sequential access.

**Design Alignment**: These results align perfectly with Judy's radix tree design - sequential access leverages cache locality, while random access requires tree traversal.

### **Table 3: Real-world Data Patterns**

| Pattern Type      | Judy Performance | Memory Efficiency | Use Case             | Recommendation |
| ----------------- | ---------------- | ----------------- | -------------------- | -------------- |
| **Database Keys** | Good             | 2-3x less         | Primary key storage  | ✅ Use Judy     |
| **Log Data**      | Excellent        | 3-4x less         | Timestamp-based data | ✅ Use Judy     |
| **Analytics**     | Good             | 2-3x less         | Time-clustered data  | ✅ Use Judy     |
| **Session Data**  | Good             | 2-3x less         | User-clustered data  | ✅ Use Judy     |

**Key Insight**: Judy performs well with real-world data patterns that have locality.

### **Table 4: Batch Operations — Bulk Add (100K elements)**

| Method                            | INT_TO_INT | STRING_TO_INT | Notes                             |
| --------------------------------- | ---------- | ------------- | --------------------------------- |
| **PHP array** (foreach assign)    | 2.2 ms     | 2.5 ms        | Baseline                          |
| **Judy individual** `$j[$k] = $v` | 4.7 ms     | 19.3 ms       | 2.1x / 7.7x slower than PHP       |
| **Judy putAll()**                 | 5.5 ms     | 18.6 ms       | ~1.0x vs individual               |
| **Judy::fromArray()**             | 3.5 ms     | 18.0 ms       | 1.3x faster than individual (INT) |

**Key Insight**: `fromArray()` provides a meaningful speedup for integer-keyed types by avoiding per-element PHP method dispatch. For string-keyed types, the Judy tree traversal cost dominates.

### **Table 5: Batch Operations — Bulk Get (10K lookups on 100K elements)**

| Method                           | INT_TO_INT | STRING_TO_INT | Notes                                  |
| -------------------------------- | ---------- | ------------- | -------------------------------------- |
| **PHP array** (`$a[$k] ?? null`) | 0.23 ms    | 0.26 ms       | Baseline                               |
| **Judy individual** `$j[$k]`     | 0.30 ms    | 0.89 ms       | 1.3x / 3.4x slower than PHP            |
| **Judy getAll()**                | 0.16 ms    | 0.80 ms       | **1.9x / 1.1x faster than individual** |

**Key Insight**: `getAll()` is significantly faster than individual lookups for integer keys (1.9x speedup) because it avoids per-element ArrayAccess overhead. For string keys the benefit is smaller but still measurable.

### **Table 6: Conversion — toArray() vs Manual Foreach (100K elements)**

| Method                  | INT_TO_INT | STRING_TO_INT | Notes                              |
| ----------------------- | ---------- | ------------- | ---------------------------------- |
| **Judy toArray()**      | 4.2 ms     | 13.2 ms       | Native C iteration                 |
| **Judy manual foreach** | 11.8 ms    | 41.0 ms       | PHP Iterator overhead              |
| **Speedup**             | **2.8x**   | **3.1x**      | toArray() avoids Iterator dispatch |

**Key Insight**: `toArray()` is 2-3x faster than building an array via `foreach` because it uses native C iteration internally, bypassing the PHP Iterator interface overhead.

### **What the two tables above actually say — and the trap they used to set**

Tables 5 and 6 win for one reason, and it is worth stating precisely because it
predicts where the win does *not* apply: **one C traversal writing straight into
the destination PHP array**. The saving is the per-element PHP↔C crossing, so it
scales with element count and it needs the whole operation to happen in a single
pass.

Read as "bulk operations are always faster than a PHP loop", that rule used to
point at the wrong tool for a *bounded* read. Before `keys($start, $end)` existed
(#96), reading one key range in bulk meant `slice($lo, $hi)->keys()` — and
`slice()` is a copy constructor, not a projection: it traverses the source,
inserts every key into a freshly allocated Judy, and then `keys()` traverses that
copy. Two traversals plus an allocation to avoid one dispatch per key, which
measured **slower than the `first()`/`searchNext()` walk it was meant to replace**.

The primitive now exists, so the rule holds again — but state it the precise way:

> Prefer a bulk operation when it does the whole job in **one** traversal.
> Composing two bulk calls to fake a third operation can cost more than the loop.

| You want | Use | Not |
| --- | --- | --- |
| every element | `toArray()` / `keys()` / `values()` | `foreach` accumulating |
| many known keys | `getAll($keys)` | a `$j[$k]` loop |
| one key range | `keys($lo, $hi)` / `toArray($lo, $hi)` | `slice($lo,$hi)->keys()`, or a walk |
| a range's size only | `populationCount($lo, $hi)` | reading it to count it |
| to skip whole blocks | `first()` / `searchNext()` | reading spans you discard |

The last two rows are the ones people get wrong in opposite directions.
`populationCount()` is right when you want a count *without* the contents — but
redundant in front of a read you are about to do anyway, since a bounded `keys()`
returns an empty array for an empty range. And a walk is right when you mean to
*skip* — seeking past keys you do not want is something no bulk call can do.

`examples/coverage-index.php` measures exactly this on a realistic workload.

### **Table 7: Atomic Increment (100K operations, 1K unique keys)**

| Method                                | INT_TO_INT      | STRING_TO_INT   | Notes                                                      |
| ------------------------------------- | --------------- | --------------- | ---------------------------------------------------------- |
| **PHP array** `$a[$k]++`              | 1.7 ms          | 2.8 ms          | Baseline                                                   |
| **Judy manual** `$j[$k] = $j[$k] + 1` | 4.7 ms          | 12.8 ms         | Two traversals (read + write)                              |
| **Judy increment()**                  | 3.0 ms          | 10.1 ms         | Single traversal for INT (JLI); two for STRING (JSLG+JSLI) |
| **increment() vs manual**             | **1.6x faster** | **1.3x faster** | Eliminates redundant lookup                                |

**Key Insight**: For `INT_TO_INT`, `increment()` achieves a true single-traversal update via `JLI`'s insert-or-get semantics (1.6x speedup). For `STRING_TO_INT`, two traversals are needed (`JSLG` to check existence for counter tracking + `JSLI` to insert/update), still providing a 1.3x speedup by keeping all logic in C rather than PHP.

---

## Key Findings

### **Memory Efficiency**
- Judy arrays provide **2-4x memory savings** compared to PHP arrays
- Memory efficiency is consistent across all dataset sizes
- String-based Judy arrays show moderate memory savings with performance trade-offs

### **Performance Characteristics**
- **Access Pattern Sensitivity**: Judy's performance heavily depends on access patterns (see "The Key Difference: O(log n) vs O(1)" section above)
  - **Random Access**: 2-9x slower than PHP arrays (Judy's weakness - O(logn) vs O(1))
  - **Sequential Access**: 2-4x slower than PHP arrays (acceptable trade-off - leverages cache locality)
  - **Range Queries**: Competitive with PHP arrays (Judy's strength - native sorted traversal)
  - **Iterator Performance**: Has overhead but becomes more efficient at larger scales
- **Key Type Impact**: Integer keys consistently outperform string keys (radix tree optimization)
- **Scale Impact**: Performance gap increases with dataset size (memory efficiency becomes more valuable)

### **Real-world Performance**
- **Database Patterns**: Judy performs well with sequential primary keys
- **Log Data**: Excellent for timestamp-based sequential data
- **Analytics**: Good for time-clustered data patterns
- **Session Data**: Effective for user-clustered data

---

## Versus the Alternatives (APCu, SplFixedArray, sorted arrays)

Everything above compares Judy against a native PHP array. That is the right
guide for *when not to use Judy*, but it is not the choice most people are
actually making. Nobody picks Judy instead of an array — they pick it instead
of **APCu**, **`SplFixedArray`**, or a **hand-rolled sorted-array index**.
This section measures those four head-to-heads.

Harness: [`examples/benchmarks/judy-bench-alternatives.php`](examples/benchmarks/judy-bench-alternatives.php).
Self-contained; skips the APCu rows with a notice when `ext-apcu` is absent.

```bash
php examples/benchmarks/judy-bench-alternatives.php            # all workloads
php examples/benchmarks/judy-bench-alternatives.php prefix 9   # one workload, 9 runs
php -d apc.enable_cli=1 -d apc.shm_size=2048M \
    examples/benchmarks/judy-bench-alternatives.php            # with APCu rows
```

### ⚠️ APCu is shared across FPM workers. Judy is not.

> **This is the single most important line in this section, and no latency
> number below changes it.**
>
> APCu lives in a shared memory segment that every PHP-FPM worker in the pool
> reads and writes. A Judy array lives in one process's heap: every worker
> builds its own copy, pays its own memory, and loses it at the end of the
> request under classic FPM. Quoting an invalidation speedup as a reason to
> replace APCu would be comparing two things that do not do the same job.
>
> Judy fits where the data lives in a **long-lived process** — Swoole,
> RoadRunner, FrankenPHP, queue consumers, CLI pipelines — or where the
> structure is **built and consumed inside one request**. Shared, cross-worker,
> read-mostly caching is what APCu is for, and php-judy does not offer it
> today. See [issue #83](https://github.com/orieg/php-judy/issues/83) and
> [`research/shm-arena/FINDINGS.md`](research/shm-arena/FINDINGS.md) for the
> feasibility spike on a shared-memory Judy arena, which concluded that it is a
> concurrency and failure-recovery subsystem rather than a feature.
>
> Because each CLI process gets its own APCu segment, the numbers below are
> single-process on **both** sides. That makes them a fair latency comparison —
> and it is precisely the setting in which APCu's real advantage is invisible.

**Redis and Memcached are deliberately absent.** Their numbers would include
IPC or network round-trips measured against an in-process data structure.
Presenting that as a head-to-head would be dishonest, and breaking the
round-trip out separately would still not make it one.

### Environment and contention

All figures below are `(measured)`; nothing in this section is projected.

- **Where**: Docker `php:8.4-cli` (Debian bookworm) on a dedicated **Linux
  x86_64** host — 24 cores, 62 GB, Ubuntu 22.04, 4 KB pages. PHP 8.4.24,
  ext-judy 2.4.2, APCu 5.1.28.
- **Load was clean.** Load average `2.26 / 0.69 / 0.24` before the sweep and
  `1.07` after, against a cores/2 = 12.0 threshold; no non-target process above
  2% CPU. These are not contention-inflated upper bounds.
- **Statistics**: 7 runs per cell, each in a fresh child process. Reported as
  **median with a 95% percentile-bootstrap CI**. A delta is only stated when the
  two CIs do not overlap; otherwise the result is reported as *no measured
  separation*.
- **Memory**: peak RSS from `getrusage()['ru_maxrss']` in a child process, not
  `memory_get_usage()` — Judy allocates outside PHP's memory manager and
  `memory_get_usage()` cannot see it. An empty-interpreter baseline (34.1 MB) is
  measured and subtracted in the "over floor" column. Peak RSS is sensitive to
  allocator and page size, so it is not portable across platforms — re-measure
  rather than quoting these figures for a different OS or architecture.

An earlier sweep of this suite was run on a contended laptop (aarch64, load
median 4.60 against a 4.0 threshold). It has been discarded. It agreed on
workloads 1 and 2, but **failed to replicate workload 4 and put workload 3's
crossover in the wrong place** — which is the practical argument for measuring
these on an idle machine rather than disclosing contamination and shipping
anyway.

#### Reproducing this

Any idle Linux box with Docker. The image needs APCu, which the repo's default
`Dockerfile` does not install:

```dockerfile
FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential libjudy-dev && rm -rf /var/lib/apt/lists/*
RUN pecl install apcu && docker-php-ext-enable apcu
COPY . /usr/src/php-judy
WORKDIR /usr/src/php-judy
# Build only — deliberately NOT `docker-php-ext-enable judy`. See the warning
# below: an ini-enabled copy silently wins over `-d extension=`.
RUN phpize && ./configure --with-judy=/usr && make -j"$(nproc)"
```

```bash
cat /proc/loadavg                      # confirm < cores/2 BEFORE and AFTER
docker build -f Dockerfile.bench -t php-judy-bench .
docker run --rm -w /usr/src/php-judy php-judy-bench \
    env PHP_INI_SCAN_DIR= php \
        -d extension=apcu \
        -d extension=/usr/src/php-judy/modules/judy.so \
        -d apc.enable_cli=1 -d apc.shm_size=2048M \
        examples/benchmarks/judy-bench-alternatives.php
```

`PHP_INI_SCAN_DIR=` disables **every** `conf.d` ini, APCu's included, so APCu
has to be loaded explicitly too or the harness will skip its rows with a
notice. Confirm both are present before trusting a run:

```bash
env PHP_INI_SCAN_DIR= php -d extension=apcu -d extension=.../modules/judy.so \
    -r 'printf("judy=%s apcu=%s\n", extension_loaded("judy")?"yes":"no",
                                     extension_loaded("apcu")?"yes":"no");'
```

> **Do not bake the extension into the image with `docker-php-ext-enable
> judy`.** If an ini file in `conf.d/` loads `judy.so`, that copy wins and a
> later `-d extension=/path/to/your/build.so` is **ignored** — PHP emits only
> `Module "judy" is already loaded in Unknown on line 0` and carries on with
> the baked-in build. Every measurement then silently describes the image's
> extension rather than the one under test.
>
> This is easy to miss and expensive when missed: a verification round during
> the #85 work concluded a memory-safety fix was ineffective, when in fact the
> container was re-running the pre-fix binary. Re-measuring with
> `PHP_INI_SCAN_DIR=` reversed the result outright.
>
> Build the extension in the image but load it explicitly at run time, with
> `PHP_INI_SCAN_DIR=` (or `php -n`) so nothing is inherited. `judy_version()`
> will **not** catch the mistake — both builds report the same version.

### 1. Prefix invalidation — a complexity-class difference

Drop one 10-key group (`user.<uid>.*`) from a store of *n* entries. **The group
being dropped is the same size at every *n***, so any growth in this column is
growth in the cost of *finding* the group, not of deleting it.

| n (total entries) | judy `STRING_TO_MIXED` (µs) | judy `*_HASH` (µs)        | PHP array (µs)      | APCu (µs)            |
| ----------------- | --------------------------- | ------------------------- | ------------------- | -------------------- |
| 10,000            | **4.40** [4.10..7.09]       | 581 [578..588]            | 143 [123..209]      | 960 [946..978]       |
| 30,000            | **5.96** [5.66..6.03]       | 2,650 [2,585..2,653]      | 602 [584..858]      | 1,351 [1,338..1,378] |
| 100,000           | **6.09** [5.87..6.77]       | 9,743 [9,550..9,963]      | 1,832 [1,797..2,042]| 2,934 [2,912..2,964] |
| 300,000           | **6.54** [6.31..7.20]       | 29,085 [29,079..29,169]   | 5,198 [5,190..5,206]| 8,357 [8,332..8,402] |
| 1,000,000         | **6.79** [6.47..7.15]       | 97,668 [97,497..97,835]   | 19,774 [19,470..19,845] | 28,615 [28,507..28,705] |

```
n = 10,000        judy         #                                              4.4 µs
                  array        ################                               143 µs
                  apcu         #########################                      960 µs
                  judy-hash    ######################                         581 µs

n = 100,000       judy         ##                                             6.1 µs
                  array        ############################                   1,832 µs
                  apcu         ##############################                 2,934 µs
                  judy-hash    ###################################            9,743 µs

n = 1,000,000     judy         ##                                             6.8 µs
                  array        #######################################        19,774 µs
                  apcu         ########################################       28,615 µs
                  judy-hash    ############################################## 97,668 µs

                  (log scale, 4.4 µs .. 97,668 µs)
```

**Scaling across the sweep** (*n* grew 100x from 10k to 1M):

| Backend                  | Growth | Shape                                     |
| ------------------------ | ------ | ----------------------------------------- |
| judy `STRING_TO_MIXED`   | 1.5x   | **flat** — cost follows the slice dropped |
| APCu (`APCuIterator`)    | 29.8x  | linear in cache size, plus a fixed setup cost |
| PHP array (key scan)     | 138.6x | linear in cache size                      |
| judy `STRING_TO_MIXED_HASH` | 168.0x | linear — no key ordering, so no adjacency |

**The CIs separate at every size in the sweep**, for every rival — Judy's
advantage is **4,212x over APCu** and **2,911x over a PHP array** at n=1M. This
is the one place in this document where the difference is a change of complexity
class rather than a constant factor: Judy's ordered keys put a namespace's keys
adjacent to each other, so `first($prefix)` + `searchNext()` walks the slice and
stops. A hash table has no adjacency, so it must test every key — which is why
APCu's regex invalidation costs 29 ms on a 1M-entry cache while the ordered trie
costs 6.8 µs.

Two caveats that keep this honest:

- **`judy-hash` is in the table on purpose.** It is the same extension, and it
  loses as badly as APCu does. The advantage belongs to *ordered keys*, not to
  Judy — choosing `STRING_TO_MIXED_HASH` for a prefix-invalidation workload
  gets you the hash-table scaling.
- **Tag-based invalidation is a third option** and is not measured here.
  Symfony's `TagAwareAdapter` maintains per-entry tag bookkeeping, which buys
  cheap group invalidation at the cost of write-path overhead and memory. If
  you can enumerate a group's keys, `apcu_delete(array)` is O(group) and this
  workload does not apply to you at all. The scan is what you pay when you
  cannot.

This is the pattern behind
[orieg/judy-cache](https://github.com/orieg/judy-cache)'s `deletePrefix()` and
[`examples/prefix-invalidation.php`](examples/prefix-invalidation.php).

### 2. Presence / dedup sets at scale

Membership tracking over 1M-10M IDs. "Dense" means IDs 0..n-1; "sparse" means
*n* IDs drawn from a keyspace 8x larger. Density is reported as a dimension
because it is the variable that decides this workload.

| Cell       | Impl       | Peak RSS (MB) | Over floor | Insert (kops/s) | Lookup (kops/s) |
| ---------- | ---------- | ------------- | ---------- | --------------- | --------------- |
| 1M dense   | **judy `BITSET`** | 34     | **0.2 MB** | 30,645 [30,156..30,862] | 43,661 [43,503..43,801] |
| 1M dense   | PHP array  | 51            | 16.9 MB    | **41,277** [41,057..41,765] | 45,831 [44,864..46,910] |
| 1M dense   | SplFixedArray | 49         | 15.2 MB    | 39,069 [37,094..39,815] | **50,441** [49,699..50,792] |
| 1M dense   | APCu       | 194           | 159.9 MB   | 7,941 [7,803..7,980] | 5,064 [5,058..5,068] |
| 1M sparse  | **judy `BITSET`** | 36     | **2.1 MB** | **16,700** [16,340..17,070] | **36,732** [36,613..36,792] |
| 1M sparse  | PHP array  | 73            | 39.1 MB    | 13,971 [13,627..14,330] | 27,143 [27,038..27,292] |
| 1M sparse  | SplFixedArray | 156        | 121.9 MB   | 9,405 [9,404..9,533] | 20,855 [20,579..21,016] |
| 1M sparse  | APCu       | 185           | 150.6 MB   | 5,298 [5,250..5,304] | 7,483 [7,466..7,504] |
| 10M dense  | **judy `BITSET`** | 35     | **0.8 MB** | 30,718 [29,930..30,964] | **43,537** [43,299..43,682] |
| 10M dense  | PHP array  | 291           | 257.0 MB   | 36,917 [36,789..37,469] | 21,011 [20,985..21,048] |
| 10M dense  | SplFixedArray | 187        | 152.6 MB   | **42,884** [42,629..43,240] | 20,709 [20,329..20,868] |

- **Memory is the headline: 321x.** 10M dense IDs cost `BITSET` 0.8 MB against
  257 MB for a PHP array (321x) and 153 MB for `SplFixedArray` (191x). A bitset
  stores one bit per key; the other two store a 16-byte `zval`. APCu cannot hold
  the 10M cell at all — its shm segment runs out first.
- **`SplFixedArray` is not a memory optimization for sparse IDs.** It must
  allocate the whole key space, so it tracks *density*, not element count — at
  1M sparse it is the worst row in the table (122 MB over floor) and it cannot
  represent IDs above its allocated size at all.
- **Lookup throughput depends on whether the working set escapes cache.** At
  **1M dense the three in-process structures are within ~15% of each other**
  and `SplFixedArray` is nominally fastest — 1M entries still fit comfortably in
  cache, so Judy's compactness buys nothing. At **10M dense Judy is 2.1x faster**
  than either, because 1 MB of bitset stays resident while 257 MB of hash table
  does not. Judy also leads the sparse cell (1.4x over an array).
- **Insert favors the PHP array and `SplFixedArray` when dense** (41.3k and
  39.1k vs Judy's 30.6k kops/s at 1M dense). Judy wins insert only when sparse.
- **APCu is the slowest and largest on every cell here.** That is expected and
  not a criticism: it is paying for a shared segment and a serialization
  boundary that the in-process structures do not have. Re-read the warning
  above before quoting this row.

See [`examples/dedup-large-stream.php`](examples/dedup-large-stream.php) for
the string-keyed version of this pattern.

### 3. Sliding-window eviction — flat, but there is a crossover

10,000 expired buckets are dropped in every cell; only the **retained** set
grows. A cost that rises with the retained column is a cost paid for data the
program is keeping.

| Retained  | judy `deleteRange()` (µs) | array key-scan (µs) | `array_filter()` (µs) |
| --------- | ------------------------- | ------------------- | --------------------- |
| 10,000    | 424 [393..667]            | **210** [180..215]  | 731 [689..935]        |
| 100,000   | **526** [473..580]        | 1,143 [1,122..1,240]| 4,598 [4,556..4,799]  |
| 1,000,000 | **464** [453..466]        | 8,353 [8,177..8,755]| 40,905 [40,704..41,199] |

**Judy does not win this workload at every size, and the plan predicted it
would.** `deleteRange()` is flat — 424 → 526 → 464 µs is non-monotonic, i.e.
noise-level across a 100x growth in the retained set — because it only touches
the expired slice. But its per-key constant is higher than a PHP array's, so:

- At **10k retained the naive array key-scan wins** (CIs separate) — it visits
  more keys but each visit is cheaper.
- **The crossover sits between 10k and 100k retained.** From 100k up Judy wins
  (2.2x at 100k, **18x at 1M**, CIs separate at both), and the gap keeps
  widening because only one of the two curves is growing.
- `array_filter()` loses to Judy at every size measured.

The practical read: `deleteRange()` is the right choice once the retained
window is somewhere above ~10k buckets, or when eviction runs on a hot path
where worst-case latency matters. Below that, a plain array scan is fine and
simpler.
See [`examples/sliding-window-rate-limit.php`](examples/sliding-window-rate-limit.php).

### 4. Floor / CIDR lookup — Judy wins lookups narrowly, updates enormously

Resolve an address to its range: the greatest range-start ≤ address. Judy does
it with `last()`; the honest alternative is a sorted array of starts plus a
userland binary search.

| Ranges    | Impl         | Lookup (ns/op)     | Build (ms)          | Insert (µs/range)          |
| --------- | ------------ | ------------------ | ------------------- | -------------------------- |
| 10,000    | judy         | **90** [89..91]    | 1.0 [0.92..0.97]    | **0.13** [0.12..0.13]      |
| 10,000    | sorted-array | 250 [249..268]     | **0.7** [0.70..0.95]| 50.6 [45.0..51.6]          |
| 100,000   | judy         | **164** [161..167] | 9.7 [9.2..10.6]     | **0.14** [0.14..0.15]      |
| 100,000   | sorted-array | 327 [321..334]     | **8.9** [8.3..8.9]  | 996 [983..1,019]           |
| 1,000,000 | judy         | **366** [364..368] | **98.3** [97.9..98.7] | **0.19** [0.19..0.20]    |
| 1,000,000 | sorted-array | 467 [463..468]     | 104.7 [104.5..105.4]| 16,248 [16,236..16,288]    |

**Lookup: Judy wins at every size, and the CIs separate at all three** — 2.8x
at 10k, 2.0x at 100k, 1.3x at 1M. The margin narrows as the table grows, which
is what you would expect: both are doing more memory traversal, and a
20-iteration PHP-level binary search loop closes on a C trie walk when the trie
gets deeper. Binary search over a packed sorted array is genuinely good at
this; Judy is simply better, and the gap is a constant factor, not a class
difference.

> An earlier contended run of this same workload reported it as a *draw*,
> because the win failed to replicate above 10k and three sweeps disagreed at
> 1M. On an idle machine it replicates cleanly. Two variables differed between
> those runs — load and CPU architecture — so the contended result is discarded
> rather than explained.

**Update cost is where they diverge by a different order of magnitude:
~85,000x at 1M ranges.** Inserting a range costs Judy 0.19 µs regardless of
table size; the sorted array must `array_splice()` its parallel arrays, which
is O(n) memmove — 16 ms per insert at 1M ranges. Build cost is a wash at 1M
(98 ms vs 105 ms).

Judy wins both metrics here, but by margins that should be weighed very
differently:

- **Static table, loaded once, queried forever** (a compiled GeoIP database, a
  fixed tariff schedule): Judy is 1.3-2.8x faster per lookup, which is real but
  is a constant factor. A sorted array is dependency-free and needs no
  extension, so this is a genuine engineering trade rather than a clear win —
  take Judy if the lookup is hot, keep the array if it is not.
- **Table that changes while it is being queried** (live CIDR blocklists,
  feature-flag ranges, dynamic shard maps): use Judy, and it is not close. A
  sorted array would spend its life splicing at 16 ms per insert. A batched
  rebuild-and-re-sort is a middle path if updates arrive in large batches.

See [`examples/ip-range-lookup.php`](examples/ip-range-lookup.php).

### Summary

| Workload                | Winner                | Margin (measured)                    | Caveat |
| ----------------------- | --------------------- | ------------------------------------ | ------ |
| Prefix invalidation     | **Judy (ordered)**    | 6.8 µs vs 29 ms (APCu) at 1M entries — 4,212x | Different complexity class; needs an *ordered* type, not `*_HASH` |
| Presence / dedup memory | **Judy `BITSET`**     | 0.8 MB vs 257 MB at 10M dense — 321x | Insert throughput is behind arrays when dense; lookup ties at 1M |
| Sliding-window eviction | **Judy above ~10k retained** | 18x at 1M; loses at 10k       | Real crossover — array scan wins on small windows |
| Floor / CIDR lookup     | **Judy on both**      | 1.3-2.8x lookup; ~85,000x on insert at 1M | Lookup edge is a constant factor; a sorted array stays a reasonable dependency-free choice |

**What this section does not show**: cross-process sharing (APCu's actual
purpose), persistence, eviction policies, TTLs, or anything about Redis and
Memcached. It measures in-process data-structure behavior only.

---

## When to Use Judy Arrays

### ✅ **Use Judy Arrays When:**

**1. Memory-Constrained Environments**
- Shared hosting with limited memory
- Docker containers with memory limits
- Applications where memory usage is critical
- **Benefit**: 3.5x less memory usage than PHP arrays

**2. Sequential Access Patterns**
- Iterating through all keys/values
- Range queries and ordered operations
- Processing data in order
- **Benefit**: Acceptable performance with significant memory savings

**3. Large Sparse Integer Datasets**
- Datasets > 1M elements with sparse integer keys
- When memory efficiency outweighs random access performance
- **Benefit**: Excellent memory efficiency and sequential performance

### ❌ **Avoid Judy Arrays When:**

**1. Random Access Patterns**
- Frequent lookups of specific keys
- Accessing keys in unpredictable order
- When random access performance is critical
- **Reason**: 2-9x slower than PHP arrays for random access

**2. Small Datasets**
- Datasets with < 100k elements
- When memory is not a constraint
- **Reason**: Overhead doesn't justify benefits

**3. Performance-Critical String Operations**
- High-frequency string key operations
- When performance is more important than memory
- **Reason**: Slower than PHP arrays for string keys

---

## Judy in PHP Developer Tooling

Judy gets proposed for PHP developer tooling — test runners, static analysers,
refactoring passes, dependency managers — more often than it fits there. This
section records which candidates were examined and rejected, and **why**,
because the reason is the part that transfers: it is what tells you whether the
*next* candidate is like these or unlike them.

**The rejections below are not measured.** Each is either a structural
argument or a pointer to a figure already in this document, and where a figure
is cited the section it comes from is named. Nobody built the rejected thing
and benchmarked it — the reasoning is why it was not built.

Several of them do, however, rest on **read source rather than recollection**,
because the premise a candidate is argued from turns out to be wrong about the
real code often enough to be the deciding factor on its own. Where an entry
cites another project's internals it names the revision it was read at, and
where a structural claim could not be checked it says so.

The one workload here that *has* been measured end to end is the coverage
index; see [Measured: the coverage-index
workload](#measured-the-coverage-index-workload) at the end of this section.
Its result is a **split verdict**, and both halves are recorded there.

### ❌ Speeding up PHPUnit's core runtime

This is the first idea most people have, and it is the wrong one. A test run's
cost is autoloading, reflection, the assertion machinery, fixture setup and
process bootstrap — not map lookups. There is no associative-array lookup in
the runner hot enough for the data structure underneath it to be observable, so
swapping a hash table for a trie buys nothing, and any swap you did make would
pay Judy's random-access penalty (2-9x slower on integer keys — see Key
Findings above) for that nothing.

Worth stating explicitly because "make PHPUnit faster" is the obvious framing
and it aims at the wrong layer. The place inside a test run where a data
structure genuinely *is* the constraint is coverage collection — see the fit
list below — and that is a different component with a different shape.

### ❌ Token streams and per-file ASTs (Rector, PHP-CS-Fixer, anything on nikic/PHP-Parser)

A tokenised file is a dense, small, sequentially-indexed integer array —
thousands of entries, not millions — and the traversal is a linear pass, not a
range query. That is squarely inside the existing "avoid Judy below ~100k
elements with random access" rule above, and it also gives up nothing Judy has
to offer: the keys are already contiguous and already in order, so ordered
iteration is free either way.

The memory argument does not rescue it. AST nodes are objects, so the Judy type
would be `INT_TO_MIXED`, which stores a `zval` pointer per slot — the zval heap
cost is identical to a PHP array's (see [When PHP Arrays Win](#when-php-arrays-win))
and only the trie overhead differs. There is no memory saving to trade the
traversal speed against.

### ❌ Composer's `autoload_classmap.php`

Already optimal, by a mechanism Judy cannot match. The classmap is a compiled
PHP array literal: OPcache compiles it once and holds the immutable array in
shared memory, so every process on the box maps the same copy at zero
per-request build cost. A Judy version would have to be constructed at run time
in each process, from some source, and would then hold a private copy per
process. That is worse on both axes before the first lookup happens.

### ⏸️ Composer's dependency solver — researched and deprioritised, not unexplored

This one is recorded differently, because it is structurally a *good* fit and
was parked anyway. The distinction matters: someone re-deriving this list
should not spend the time rediscovering it.

**Why it fits.** The solver works over package versions identified by dense
integer literal ids, and spends its time on set operations — union,
intersection, difference over candidate pools — which is the shape `BITSET`
plus the C-level set methods exist for. Memory is a real, long-standing pain
point there: `COMPOSER_MEMORY_LIMIT` exists because resolving a large graph can
exhaust `memory_limit`, and a Judy array is allocated outside PHP's memory
manager entirely, so it does not count against that limit at all.

**Why it was parked anyway.**

- **Invasive, in someone else's codebase.** The pool and rule representation is
  load-bearing across the whole solver, so this is not a swap behind an
  interface — it is a rewrite of the solver's core that introduces a hard
  `ext-judy` dependency into a tool that has to run everywhere, including where
  no extension can be installed. That pushes it toward an optional backend,
  which doubles the code paths that must agree.
- **The trade is not free even if it lands.** The solver does a great deal of
  random access on integer ids, which is Judy's documented weakness (2-9x
  slower — Key Findings above). The win would be memory and set-operation
  throughput bought with lookup latency — favourable only if memory is the binding
  constraint on the run, which is plausible on large graphs and unproven on
  small ones.
- **Nobody has measured it.** There is no benchmark in this repo for it, and
  the two points above are entirely structural. Do not read this entry as a
  claim that it would win.

Honest status: **plausible, unmeasured, high cost**. If someone picks it up,
the first artifact should be a standalone harness over a captured real
dependency graph — not a patch.

### ❌ PHPStan / Psalm result-cache invalidation

The claim: a static analyser's result cache is a file-dependency graph,
invalidation is a reverse-transitive closure over integer node ids, and the
repeated set unions would be leaner in a `BITSET` than in a PHP loop plus
`array_unique`.

Only the first clause survives contact with the code. This one was checked
against source rather than recalled — `phpstan/phpstan-src` at `6c642f1` and
`vimeo/psalm` at `7385c40`.

**It is not a transitive closure.** `ResultCacheManager::restore()` expands each
changed file by exactly **one hop**: it appends that file's cached
`dependentFiles` (or, for a body-only change to a file containing a trait, its
`usedTraitDependentFiles`) to a flat `$filesToAnalyse` list, and never
re-expands the files it just appended. There is no worklist, no fixed point.
What substitutes for transitivity is a different mechanism entirely — a hash of
each file's *exported nodes* (its public signature surface). If those changed,
PHPStan sets `$newFileAppeared` and appends every file that had a cached error,
which is a conservative widening, not a graph walk. Deleted files contribute
their dependents by the same one-hop rule. The whole thing ends in a single
`array_unique()` over a list of strings.

**The node ids are not integers.** The cached graph is
`array<string, array{fileHash: string, dependentFiles: list<string>}>`, keyed by
absolute file path, and the appended values are file paths too. To get to a
`BITSET` you would first have to assign integer ids to every path — which
requires exactly the string-keyed map Judy is *worse* at than a PHP array (see
[Avoid Judy Arrays When](#-avoid-judy-arrays-when)), rebuilt on every run,
to save work on a set operation that is smaller than the map you built to
enable it.

**And it is nowhere near the size where Judy competes.** The graph holds one
entry per analysed file, so a 10k–20k-file project is a 10k–20k-node graph, and
the set being unioned — `$filesToAnalyse` — is smaller still: changed files plus
their direct dependents plus, in the widening case, the previously-errored
files. That is one to two orders of magnitude below the ~100k-element threshold
this document gives for random-access workloads, on the wrong side of it.

**The closure is not hot, and the enclosing method proves it.** Before the
expansion loop runs, `restore()` calls `getFileHash()` for **every** analysed
file, which is a `hash_file('sha256', ...)` — a full read and digest of the
entire source tree. The invalidation pass then walks the same files once in
PHP. Any saving on the set union is bounded by a term that is already dwarfed
by the hashing beside it, before you even reach parsing and analysis.

**Persistence closes the last door.** The cache is not held in memory between
runs; it is written as a `var_export()`ed PHP file and read back with `require`.
That is the same mechanism that makes
[Composer's `autoload_classmap.php`](#-composers-autoload_classmapphp)
unbeatable here: OPcache compiles the literal once and every process maps it,
whereas a Judy version must be constructed at run time, per process, from that
same file. Judy would be paying a build cost to replace something whose build
cost is zero.

**Psalm is the same shape.** `FileReferenceProvider` keeps
`$file_references[$file] = ['a' => list<string>, 'i' => list<string>]` plus
class-keyed maps whose keys are lowercased FQCN strings, and
`calculateFilesReferencingFile()` / `calculateFilesInheritingFile()` each end in
`array_unique()` over a one-hop list of paths. String keys, one hop, same
verdict.

**Does the long-lived-process angle rescue it?** No. PHPStan's `--watch` hands
off to PHPStan Pro, which is closed source — *this could not be verified* and
nothing here should be read as a claim about it. Psalm's language server is
in-repo and genuinely long-lived, but what it holds resident is `ClassLikeStorage`
and `FileStorage` objects — fat objects with dozens of array properties each —
not the reference maps. That is the AST case again: an `INT_TO_MIXED` or
`STRING_TO_MIXED` slot holds a `zval` pointer, so the storages cost the same
either way and only the container overhead differs (see
[When PHP Arrays Win](#when-php-arrays-win)). The reference maps are the small
part of that process's footprint, so shrinking them does not change the
calculus on its own. *The split between storages and reference maps in a
resident language server was not measured — that claim is structural.*

Honest status: **rejected on the code, not on a benchmark.** Two of the claim's
premises are factually wrong about both tools, and the two that would matter
even if they were right — size and hotness — both fail independently.

### ❌ Doctrine's UnitOfWork during batch imports

The claim: `identityMap`, `entityStates` and `originalEntityData` are keyed by
`spl_object_id` — sparse integers, Judy's native key shape — and are why people
call `clear()` every N rows during a batch import. Candidate: `INT_TO_MIXED` for
the maps, `BITSET` for the states.

The three maps do not share a verdict, so they are assessed separately, against
`doctrine/orm` at `c9a7332`.

**`identityMap` is not keyed by `spl_object_id` at all.** It is
`array<class-string, array<string, object>>` — the root entity class name, then
the flattened identifier hash produced by `getIdHashByEntity()`. Two levels,
both string-keyed, no object id anywhere. The nesting is load-bearing rather
than incidental: `computeChangeSets()` iterates it class-major so it can fetch
`ClassMetadata` once per class and skip read-only classes wholesale, and
`getIdentityMap()` returns the nested array as public API. Flattening it to a
composite key to fit a single Judy array would break that iteration, and the
key type it would land on is Judy's slowest. **Rejected**, and on a premise that
is simply not true of the code.

**`originalEntityData` is keyed by `spl_object_id`, and it is the AST argument
again.** The type is `array<int, array<string, mixed>>` — one PHP array of field
values per managed entity. `INT_TO_MIXED` stores a `zval` pointer per slot, so
each of those inner arrays costs precisely what it costs today and only the
outer container's per-entry overhead changes (see
[When PHP Arrays Win](#when-php-arrays-win)). The inner arrays *are* the
footprint. Doctrine's own docblock on the property notes it already leans on
copy-on-write so that a field value is shared with the entity's property until
the user modifies it, which means the outer container's share of the retained
bytes is smaller still. `entityIdentifiers` is the same type with the same
verdict. **Rejected** — the entity graph is the memory, not the map.

**`entityStates` is the one that could work, and it is too small to be worth
it.** Values are `self::STATE_*` small ints, and within `UnitOfWork.php` the
only values ever *stored* are `STATE_MANAGED` and `STATE_REMOVED` — the
`STATE_NEW` and `STATE_DETACHED` assignments are commented out, because
`getEntityState()` derives those from absence. Two states plus absence is
exactly what a pair of `BITSET`s expresses, so unlike the other two maps there
is no zval-per-slot objection here. It fails on proportion instead: this is one
scalar per managed entity sitting beside that entity's object, its
`originalEntityData` array and its `entityIdentifiers` array. Eliminating its
storage entirely leaves everything `clear()` exists to release still resident.
**Plausible in isolation, pointless in isolation** — and it is the only one of
the three with any structural case at all.

**The keys are dense, not sparse — the premise inverts.** `spl_object_id()`
returns the object-store handle: a small integer counter starting at 1, recycled
the moment an object is freed. Doctrine says so itself, in the `getEntityState()`
comment explaining why NEW and DETACHED are not cached — *"the object hash can be
reused"*. Probed directly on PHP 8.5.8: ten retained objects got ids 1–10; a
freed object's id was handed straight to the next allocation; and retaining one
companion object per entity gave 1, 3, 5 … 15, a density of 0.53. Judy's
sparse-key memory advantage in
[When Judy Saves Memory](#when-judy-saves-memory) above is demonstrated at a key
step of 1000 — density 0.001. Dense low integers are the shape a PHP array
handles most cheaply, not the shape that buys Judy anything.

**Size settles what is left.** Doctrine's own batch-processing chapter uses
`$batchSize = 20`, flushing and clearing every 20 rows, so between clears these
maps hold tens of entries — five orders of magnitude below the threshold where
Judy starts winning. And the pathological run people are actually fixing when
they add `clear()` — the one that never clears and dies at 100k rows — dies
because 100k entity objects and 100k field-data arrays are resident, which no
change to the key structure touches.

Honest status: **rejected.** The kill is the same one the AST entry makes: the
values dominate the footprint and `INT_TO_MIXED` stores them as zvals either
way. `entityStates` escapes that objection on value shape and then loses on
proportion. Nothing here was measured, and nothing here needs to be — the
element counts are below the range where a measurement would be interesting.

### The constraint underneath several of these: one process, one arena

php-judy has no shared arena. A Judy array lives in the heap of the process
that built it and cannot be read by another process. This is stated for the
caching case in
[Versus the Alternatives](#versus-the-alternatives-apcu-splfixedarray-sorted-arrays);
[issue #83](https://github.com/orieg/php-judy/issues/83) and
[`research/shm-arena/FINDINGS.md`](research/shm-arena/FINDINGS.md) carry the
feasibility spike.

For developer tooling the consequence is specific: **anything whose value comes
from a cache shared across processes cannot use Judy.** A parallel test runner
(ParaTest, `--process-isolation`) must have each worker accumulate its own
structure and merge them in the coordinator, shipping each index across the
process boundary like any other data. That is fine for a coverage index — the
merge is one set-union call, and a `BITSET`'s keys serialise as a flat run of
integers rather than as a nested map. It is fatal for anything that wants
APCu-style cross-worker sharing, such as a static-analysis result cache several
concurrent runs would all read.

It is also the same reason the classmap rejection above holds: OPcache's shared
memory is precisely the property Judy does not have.

### ❌ A Judy-backed store inside `sebastian/php-code-coverage`

The data-structure thesis holds and the packaging does not. Verified against the
upstream source rather than recalled.

Through 14.2.x the processed shape was
`array<non-empty-string, array<positive-int, null|list<TestIdType>>>` — file, then
line, then a list of test-identifier **strings**, which is what makes a full-suite
coverage run exhaust `memory_limit`. In **14.3.0 (tagged 2026-08-07)** it became
`array<non-empty-string, array<positive-int, null|array<TestIndexType, positive-int>>>`:
test ids interned to integers (`testIdToIndex`, `nextTestIndex`) and per-line values
changed from id lists to **hit counts**. Driven by issue #1090, where `.cov` files
grew 8 MB to 467 MB and broke paratest; the serialization format was bumped v1 to v2.

So test-id interning — one of the four properties the packed-key design in
[`examples/coverage-index.php`](examples/coverage-index.php) relies on — is now
upstream, in plain PHP arrays, with no extension involved.

**Three reasons it still does not become a package**, none of which is about the
data structure:

- **No integration seam.** `CodeCoverage` is `final` and holds a private
  `ProcessedCodeCoverageData`, which is itself `final`, marked
  `@internal This class is not covered by the backward compatibility promise`, and
  reshaped in a *minor* release. `src/` contains two interfaces and neither is a
  data store. A third party cannot implement anything — fork or patch only.
- **A fork wins nothing anyway.** Every report path calls `lineCoverage()` and
  materialises the entire nested array; `Node\Builder` then builds a second full
  tree over it. Peak becomes *array size plus Judy size* — strictly worse than
  today. The memory win only exists while the data never becomes a PHP array, and
  rendering anything turns it into one.
- **The maintainer has ruled on the problem.** Issue #737 ("streaming coverage data
  to file at runtime"), opened because reporting "might run out of gigabytes",
  was closed 2026-04-24 with: partition the suite, `--coverage-php` per partition,
  merge with PHPCOV, "I do not think that changes to php-code-coverage are needed
  here."

The strongest competing mitigation is not `memory_limit` either. ParaTest #924 —
754k LOC, 2 GB `.cov` per worker, >20 GB merging — was answered with precise
`#[CoversClass]` / `#[CoversMethod]` usage, which cuts the *number of pairs* and can
cut it by far more than a bytes-per-pair win. It competes with this idea rather than
composing with it.

**What would reopen it:** the maintainer's own stated intent on #1090 to split
`CodeCoverage` into a data object and a control object. If that lands *with an
interface*, the seam exists. A per-file or streaming accessor replacing
`lineCoverage(): array` on the report path would also change the answer — without
one, no store can hold the peak down, whatever it is made of.

### ❌ Infection's mutation-coverage index

Rejected for the same two structural reasons, plus a third that is decisive on its
own. Verified against `infection/infection` at 0.35.0.

**It does not consume `php-code-coverage`.** Infection requires `sebastian/diff`
only, reads PHPUnit's **XML** coverage report (`index.xml` plus one file per source
file), and re-parses it into its own objects. So none of the 14.3.0 interning work
above reaches it, and there is no shared representation to build on.

- **The seam exists but is not injectable.** `Tracer`, `TraceProvider` and `Trace`
  are interfaces — and all three are `@internal`. `Container` is `final`, its
  `offsetSet` is `private`, `withValues()` accepts only scalar CLI options, and
  `Container::create()` hard-wires `Tracer` to the XML-coverage chain with no
  branch. The sanctioned extension points are enumerated in
  `ProjectCodeProvider::EXTENSION_POINTS` and enforced by an architecture test;
  coverage and tracing are not among them.
- **It materialises and never evicts.** `TraceProviderAdapterTracer::$indexedTraces`
  is written and never `unset`. `TestLocations` returns its line map **by
  reference**, explicitly for performance. A Judy store would sit beside that array,
  not replace it.
- **The scale is wrong.** `TestLocations` is built **per source file** — hundreds of
  integer keys, not millions. That is squarely inside the "avoid Judy below ~100k
  elements with random access" rule above, the values are objects (so `INT_TO_MIXED`
  stores the same zvals), and the hot lookup is `$byLine[$line] ?? []`, not a range
  query — so the bounded-read primitive does not apply either.

### ✅ What does fit

Kept short on purpose — the rejections above are the point of this section.

- **Coverage indexes.** `file -> line -> [test ids]` is a deep nested map of
  tens of millions of live zvals, which is what turns a full-suite coverage run
  into a `memory_limit` fatal. Flattened into one `BITSET` over a packed
  `[file | line | test]` key it becomes sparse integer keys with no nested
  containers, allocated outside `memory_limit`.
- **Test-impact selection.** Because that key is ordered file-major, every test
  id for one `file:line` is a contiguous block, so "which tests must this diff
  run?" is a bounded range read rather than a scan.
- **Prefix and namespace walks.** Ordered string keys make "everything under
  `App\Foo\`" a `first()` + `searchNext()` walk that stops at the end of the
  slice — the one place in this document where the difference is a complexity
  class rather than a constant factor (Prefix invalidation, above).

[`examples/coverage-index.php`](examples/coverage-index.php) is the worked
example for the first two, and it carries the soundness rule that makes
selection safe: a changed line with no recorded coverage must widen the
selection, never return nothing. What it buys and what it does not is measured
directly below.

### Measured: the coverage-index workload

Run on an idle 24-core Ubuntu 22.04 host, in Docker (PHP 8.4.24), extension
built from `main` at `f35ff20`. Load stayed between 0.11 and 0.56 across every
repetition with no non-target process above 2% CPU. All variants were asserted
to answer identically before timing. Two scales, 7 and 25 repetitions; figures
are medians with a 95% bootstrap CI on the median.

**5000 files x 500 lines, 10,000 tests (1,578,994 line/test pairs)**

| variant | peak RSS | index (peak − floor) | merge |
| ------- | -------- | -------------------- | ----- |
| empty PHP process (floor) | 22.88 MB | — | — |
| nested PHP array | 277.12 MB | 254.25 MB | 52.31 ms |
| Judy, `mergeWith()` | **50.06 MB** | **27.38 MB** | 57.55 ms |
| Judy, `union()` | 56.44 MB | 33.56 MB | 112.19 ms |

**800 x 300, 2,000 tests (185,700 pairs)**

| variant | peak RSS | index (peak − floor) | merge |
| ------- | -------- | -------------------- | ----- |
| empty PHP process (floor) | 22.88 MB | — | — |
| nested PHP array | 52.12 MB | 29.44 MB | 5.83 ms |
| Judy, `mergeWith()` | **25.88 MB** | **3.00 MB** | 6.81 ms |
| Judy, `union()` | 26.44 MB | 3.38 MB | 12.99 ms |

#### ✅ Memory: Judy wins, and the win grows with scale

Peak RSS is **5.53x lower** at the large scale (95% CI [5.515, 5.536]) and
2.02x lower at the small one (CI [2.014, 2.029]).

Do not lean on that small-scale figure. Peak RSS includes the ~22.9 MB PHP
runtime floor, which dominates a small index and drags the ratio down — 2 of 25
runs fell below 2x. **The stable figure is the index-only ratio (peak minus
floor), which is ~9.3–9.8x at *both* scales.** The apparent scale-dependence is
the fixed floor's shrinking share, not a property of the data structure.

This is the clause that matters for the motivating problem: a full-suite
coverage run dying on `memory_limit`. Judy's index is also allocated outside
`memory_limit` entirely, which the ratio above does not capture.

#### ⚖️ Merge: the array wins below ~2.2M pairs, Judy wins above

The two configurations originally measured both favoured the array — `mergeWith()`
~10% slower at the large scale (57.55 ms vs 52.31 ms, CI [0.898, 0.913]) and ~16%
slower at the small one, with zero of 32 runs at parity. That section also
predicted that "a larger or more heavily overlapping workload may close it".

**A later scale sweep confirms the prediction and locates the crossover.** Ratio is
array/judy, so **>1.0 means Judy wins**. 12 build pairs, percentile-bootstrap CI:

| scale | line/test pairs | array | judy | array/judy | 95% CI |
| ----- | --------------- | ----- | ---- | ---------- | ------ |
| 800x300x2000 | 185,700 | 5.75 ms | 6.82 ms | 0.845 | [0.840, 0.850] |
| 2000x400x5000 | 626,481 | 21.07 | 22.23 | 0.947 | [0.945, 0.950] |
| 5000x500x10000 | 1,578,994 | 52.71 | 57.59 | 0.915 | [0.913, 0.916] |
| 6000x500x12000 | 1,894,786 | 66.73 | 68.42 | 0.974 | [0.974, 0.976] |
| **7000x500x14000** | **2,209,864** | **78.31** | **77.65** | **1.005** | **[0.999, 1.017]** |
| 8000x500x16000 | 2,525,044 | 87.72 | 86.59 | 1.012 | [1.009, 1.015] |
| 9000x500x18000 | 2,840,768 | 98.18 | 95.25 | 1.030 | [1.028, 1.032] |
| 10000x500x20000 | 3,157,478 | 106.31 | 100.12 | 1.059 | [1.058, 1.065] |
| 16000x500x32000 | 5,053,012 | 172.83 | 143.86 | **1.201** | [1.199, 1.203] |

Parity lands at roughly **2.2M pairs** (the CI straddles 1.0 there), the CI lower
bound clears 1.0 from about **2.5M**, and Judy is **20% faster by 5.05M**.

The mechanism is unchanged and is exactly what the asymptotics predict: the
in-place array merge moves a whole test list by refcount where a line is new to
the target, making it O(distinct lines) + overlap against `mergeWith()`'s O(keys).
The refcount shortcut wins while the number of distinct lines is small relative to
the pairs; Judy's flat per-pair constant wins once it is not.

One caveat on reading the table: the 626k row (0.947) sits off the monotone trend
because that shape's overlap ratio differs. This is a scale trend, not a smooth
curve — the crossover moves with overlap ratio as well as with size.

`union()` is excluded from the comparison by construction: it allocates a third
index and lands at 0.46x.

#### Note: PR #123 did not move this

`mergeWith()` was made 10-24% faster for most types by removing a redundant full
descend per element (#121, PR #123). Measured on the same host, 12 build pairs:
`INT_TO_INT` 0.786, `INT_TO_PACKED` 0.779, `INT_TO_MIXED` 0.777, `STRING_TO_INT`
0.840, `STRING_TO_MIXED` 0.818, and the `*_HASH`/`*_ADAPTIVE` types 0.876 with
`optimizeIteration` on. Unmirrored `*_HASH`/`*_ADAPTIVE` gain ~3% (the fix removes
a zval-boundary wrapper there, not a descend). Effect is flat per element across a
16x scale sweep — a constant-factor win, as designed.

**It changes nothing in the table above, and could not have.** The coverage index
is built as a `Judy::BITSET`, and `BITSET` is the one type the change explicitly
excludes because its merge branch reads no value at all. Measured end to end on
the gate workload: **1.0001, CI [0.9981, 1.0012]** — and `BITSET` is the true
zero-control for the whole change, straddling 1.0 at every scale.

The lesson worth keeping: the workload that motivated the optimisation was the
single type the optimisation could not touch.

#### ❌ Selection: also not a Judy win here

The per-id `first()`/`searchNext()` walk runs at 0.25x the array's selection
speed and the bounded `keys($lo, $hi)` read at 0.42x. Bulk is ~1.7x faster than
the walk (0.028 ms vs 0.047 ms at the large scale), which confirms the value of
the bounded read *relative to the walk* — but both remain slower than a plain
PHP array lookup, which reaches a line's test list in two hash lookups and then
iterates inside the VM.

#### What to take from this

The coverage index is primarily a **memory** result. Reach for it when the array
shape is what is killing the run.

On speed, the answer is scale-dependent and was originally stated too flatly:
**below ~2.2M line/test pairs the array is faster at merging; above ~2.5M Judy
is, reaching +20% by 5M.** Selection remains a Judy loss at every scale measured.
So "keep the array when it fits in memory" holds for small and mid-size suites,
and stops holding for merging on large ones.

These numbers are one workload family on one host, and the crossover moves with
overlap ratio as well as with size; the example is runnable and prints the same
table for yours.

---

## Bundled libJudy optimizations (measured)

Since the extension began bundling its own patched libJudy
([#142](https://github.com/orieg/php-judy/issues/142); decision record in
[`research/libjudy-modernization/FINDINGS.md`](research/libjudy-modernization/FINDINGS.md)),
two engine-level integer-path optimizations (O1, O3 — tables below) and three
string-layer patches (O4a/O4b/O4d,
[PR #154](https://github.com/orieg/php-judy/pull/154); gate tables in
FINDINGS §7.7) have merged with measured gates. The tables below are
`(measured)` 2026-08-18 on an idle 24-core i9-12900F (Alder Lake, x86-64,
gcc 11.4.0), with a C harness driving the bundled library directly — PHP-level
per-op figures include extension call overhead not measured here. Protocol, per
the standing discipline: 3 arms including a control whose objects were verified
**byte-identical** to base (flag-only for O1, comment-only for O3), **5
independent builds per arm with randomized link order**, interleaved trials,
per-build medians, percentile-bootstrap CIs over builds, runs pinned with
`taskset`. Ratios are time treatment/base — **below 1.0 is faster** — and a
cell is claimed only when its CI clears the control-calibrated noise floor.
That floor is **per-residency** — **~3% for cache-resident (L3) cells, ~1.3%
for out-of-cache cells** — revised 2026-08-18 by an adversarial re-review that
pooled ~97 control cells across the four Stage 3 gate rounds (worst
byte-identical-control excursion +2.97%, one control CI excluding 1.0 at
2.59%; FINDINGS §11.10). Under the revised floor, three previously-claimed O4
string-layer cells were reclassified (`urand16`/`varlen` overwrite not
claimed, `urand16` iterate artifact-risk — FINDINGS §7.7); every cell in the
O1/O3 tables below survives it, with one downgrade noted under the O1 table.

Two standing caveats on this whole section. **Mechanism sentences are
hypotheses**: the bench host has no PMU, so statements about *why* a cell
moves (chain shortening, I-cache, MLP) are consistent-with-the-sign
hypotheses, not measurements (FINDINGS §11.7, §11.10). **Release-level CI
runs do not corroborate individual optimizations**: the post-release GHA
bench-compare run showed string-keyed ops improving as much as int-keyed ones
where O1+O3 predict ~0 on strings, against a foreign-provenance baseline
binary, and self-stamped CONTAMINATED — the defensible claim from such runs
is only "the shipped bundle is not slower than the previous release, and int
reads moved in the right direction" (FINDINGS §11.10).

### O1 — hardware popcount for bitmap leaves (merged, [PR #149](https://github.com/orieg/php-judy/pull/149))

| corpus | get ratio [95% CI] | insert ratio [95% CI] |
| --- | --- | --- |
| `wdense` (dense integer keys) | **x0.8249 [0.8003, 0.8284]** | **x0.9053 [0.8897, 0.9188]** |
| `wbase16` | **x0.8413 [0.8369, 0.8481]** | **x0.9691 [0.9616, 0.9783]** |
| `wdense`, out-of-cache (n=4×10⁷, ~330 MB) | **x0.9270 [0.9261, 0.9299]** | **x0.9206 [0.9092, 0.9273]** |
| `wbase4` / `urand16` (string keys) | null / not claimed | null |

**Scope**: `Judy::INT_TO_*` with dense-ish integer keys only. The duty-cycle
census behind this is in FINDINGS.md §5.1 — high-entropy string keys make
**zero** popcount calls per lookup, so the `Judy::STRING_*` types see nothing,
and `Judy::BITSET` sees nothing (Judy1's bitmap-leaf arm is a plain bit test,
not a popcount). **Not claimed**: `wclust` get (−2.4%, sharing its cell with a
control excursion) and `urand16` (−0.7%) — both inside the noise floor the
byte-identical control calibrated. **Downgraded** (2026-08-18 re-review):
`wbase16` insert ×0.9691 is *directionally consistent but weakly bounded* — a
~3% point estimate against the revised ~3% L3-resident floor (FINDINGS
§11.10). The get claims and the `wdense` insert cells clear the revised floor.

### O3 — word-access node metadata (merged, [PR #150](https://github.com/orieg/php-judy/pull/150))

The stock library reassembles a 7-byte big-endian field with 7 dependent byte
loads on every branch descend; O3 replaces that with one word load + byte-swap.
Measured on top of the O1-merged tree, so the two stack:

| corpus | get ratio [95% CI] | insert ratio [95% CI] |
| --- | --- | --- |
| `wdense` | **x0.6939 [0.6796, 0.7014]** | **x0.8341 [0.8226, 0.8396]** |
| `wbase16` | **x0.8132 [0.8068, 0.8187]** | **x0.8651 [0.8444, 0.8726]** |
| `wbase4` | **x0.8881 [0.8763, 0.9028]** | **x0.8519 [0.8477, 0.8590]** |
| `wclust` | **x0.8947 [0.8909, 0.9005]** | **x0.8811 [0.8738, 0.8901]** |
| `wdense`, out-of-cache (n=4×10⁷) | **x0.6944 [0.6931, 0.6971]** | **x0.8172 [0.8106, 0.8228]** |
| `urand16` (string keys) | ≤1.2%, not claimed | not claimed |

Unlike O1 — whose gain shrinks from ~17% cache-resident to ~7% out of cache —
**the O3 win survives out-of-cache fully intact** (x0.694 at both residencies).
The chain-shortening explanation for that shape is a hypothesis, not a
measurement — no PMU exists on the bench host, chain arithmetic prices only
part of the win, and the out-of-cache figure leans on the harness's
independent-key memory-level parallelism, which serially-dependent consumers
will not fully see (FINDINGS §11.7). All 36 control cells were null, and
`J1MU`/`JLMU` memory accounting is byte-identical pre/post for both
optimizations — memory, the one axis this project measurably wins, is untouched.
Full mechanism, controls, and the execution history:
[FINDINGS.md §11](research/libjudy-modernization/FINDINGS.md).

---

## Optimal Usage Patterns

### **✅ DO: Use Judy's Iterator (Best Performance)**
```php
$judy = new Judy(Judy::INT_TO_INT);
// ... populate data ...

// Optimal: Use Judy's iterator
foreach ($judy as $key => $value) {
    // Process each key-value pair
    echo "$key => $value\n";
}
```

### **✅ DO: Sequential Access with Sorted Keys**
```php
$judy = new Judy(Judy::INT_TO_INT);
// ... populate data ...

// Judy keys come out sorted already — no array_keys(), no sort()
foreach ($judy->keys() as $key) {
    $value = $judy[$key];
    // Process value
}

// Only need part of the key space? Bound the read instead of filtering after
foreach ($judy->keys(1000, 2000) as $key) {
    // one traversal of that range, not of the whole array
}
```

### **✅ DO: Hybrid Approach (Best of Both Worlds)**
```php
// Use Judy for storage and sequential access
$judy = new Judy(Judy::INT_TO_INT);
// ... populate data ...

// Convert to PHP array for random access when needed
$php_array = $judy->toArray();

// Now you can do fast random access
$value = $php_array[50000]; // Fast random access
```

### **❌ DON'T: Random Access Patterns**
```php
$judy = new Judy(Judy::INT_TO_INT);
// ... populate data ...

// Avoid: Random access is very slow
$random_keys = [1000, 50000, 2000, 75000, 3000];
foreach ($random_keys as $key) {
    $value = $judy[$key]; // Very slow!
}
```

---

## Understanding `Judy::memoryUsage()`

The `memoryUsage()` method reports the memory a Judy array occupies outside PHP's memory manager — memory that `memory_get_usage()` and Xdebug's memory profiler are both blind to, so this method is the only in-process view of it.

**It returns two different kinds of number.** Integer-keyed types return libJudy's own exact accounting. String-keyed types return an approximation the extension maintains itself, because libJudy ships no accounting macro for JudySL or JudyHS. Read the type table before comparing two figures.

### Return Values by Type

| Judy Type                  | Underlying C Type       | C Macro               | `memoryUsage()` Return                                          |
| -------------------------- | ----------------------- | --------------------- | --------------------------------------------------------------- |
| `BITSET`                   | Judy1                   | `Judy1MemUsed` (J1MU) | `int` — bytes used                                              |
| `INT_TO_INT`               | JudyL                   | `JudyLMemUsed` (JLMU) | `int` — bytes used                                              |
| `INT_TO_MIXED`             | JudyL                   | `JudyLMemUsed` (JLMU) | `int` — bytes used (Judy storage only, excludes PHP zvals)      |
| `INT_TO_PACKED`            | JudyL                   | `JudyLMemUsed` (JLMU) | `int` — bytes used (Judy storage only, excludes packed buffers) |
| `STRING_TO_INT`            | JudySL                  | *(no macro)*          | `int` — **approximate**, payload only                           |
| `STRING_TO_MIXED`          | JudySL                  | *(no macro)*          | `int` — **approximate**, payload only                           |
| `STRING_TO_INT_HASH`       | JudyHS + JudySL         | *(no macro)*          | `int` — **approximate**, payload only (keys counted twice)      |
| `STRING_TO_MIXED_HASH`     | JudyHS + JudySL         | *(no macro)*          | `int` — **approximate**, payload only (keys counted twice)      |
| `STRING_TO_INT_ADAPTIVE`   | JudyL + JudyHS + JudySL | *(no macro)*          | `int` — **approximate**, payload only                           |
| `STRING_TO_MIXED_ADAPTIVE` | JudyL + JudyHS + JudySL | *(no macro)*          | `int` — **approximate**, payload only                           |

**What the string-keyed approximation counts**: the Judy C library provides no `JudySLMemUsed` macro (JudySL is internally a chain of JudyL arrays, one per key byte, and JudyHS exposes only get/insert/delete/free-all), so the extension keeps its own running total instead. Per live entry it counts:

- the **stored key bytes**, once per structure that holds a copy. The `_HASH` types hold every key twice — once in the JudyHS value store, once in the JudySL key index that makes ordered iteration possible — so their figure is roughly twice the key bytes. `_ADAPTIVE` packs keys shorter than 8 bytes into the index word and only copies longer ones into JudyHS.
- one **machine word per value slot** (8 bytes on 64-bit).
- the **`zval` box** (`sizeof(zval)`, 16 bytes on 64-bit) allocated for each `_MIXED` value.

**What it excludes** — and therefore why it is only an approximation: every byte libJudy allocates for its own trie branches, leaves and JudyHS hash structures; allocator rounding; and the PHP heap reachable from a stored `zval` (the string or array it points at, which `memory_get_usage()` *does* see). The figure is a **lower bound on the true footprint**. How far below depends on the key distribution: keys with heavy prefix sharing compress well in the trie and land close to the reported figure, while random keys leave the real footprint a small multiple of it. Use it to track growth and to compare populations of the same type, not to compare a string-keyed array against the exact figure an integer-keyed one reports.

**For the exact string-keyed footprint**, measure outside the extension: peak RSS via `getrusage()['ru_maxrss']` in a separate process, or Valgrind Massif (`examples/benchmarks/judy-bench-memory.php`), which attributes libJudy's `malloc` traffic directly.

**Debug dumps carry the caveat too**: `var_dump()` of a string-keyed Judy shows a `memoryUsageIsApproximate => true` entry beside `memoryUsage`, so the distinction is visible in an IDE variable pane without consulting this table.

**INT_TO_MIXED note**: The value returned is only the JudyL trie memory. The PHP `zval` pointers stored in each slot consume additional PHP heap memory not reflected in `memoryUsage()`.

### How `memoryUsage()` Works Internally

For `BITSET` and `INT_TO_*` types, the Judy library maintains a `TotalMemWords` counter in the root JPM (Judy Population/Memory) structure. `memoryUsage()` reads this counter and multiplies by `sizeof(Word_t)` — an O(1) operation with no traversal.

For the string-keyed types the extension maintains its own counter in the object, adjusted on the same insert and delete paths that maintain the element count — so that call is O(1) as well, and it is safe to read at a breakpoint. It survives `clone`, `serialize()`/`unserialize()`, `slice()`, `deleteRange()`, `mergeWith()`, the set operations and the bulk writers, and returns to exactly `0` for a newly constructed array, after `free()`, and after the last key is unset.

```php
$j = new Judy(Judy::INT_TO_INT);
$j->memoryUsage(); // 0 — empty array, no JPM allocated yet

for ($i = 0; $i < 10000; $i++) {
    $j[$i] = $i;
}
$j->memoryUsage(); // e.g. 65536 — bytes used by Judy trie nodes

$j->free();
$j->memoryUsage(); // 0 — freed
```

### When Judy Saves Memory

**Large sparse integer sets**: Judy's compressed trie uses memory proportional to *population*, not *key range*. For sparse integer keys (e.g., `[0, 1000000, 2000000, ...]`), Judy is often more memory-efficient than a PHP array because its compressed structure only requires nodes for the populated keys.

```
10K elements with key step = 1000:
  PHP array:       ~530 KB
  Judy INT_TO_INT: ~ 90 KB   (5-6x less)
  Judy BITSET:     ~ 50 KB   (10x less)
```

**Dense integer counters**: `INT_TO_INT` with sequential keys uses 2-4x less memory than a PHP array at large scale (100K+ elements), because Judy compresses dense key ranges into compact leaf nodes.

**Bitset/presence tracking**: `BITSET` stores only the bit index with no value storage. At 1M elements, a Judy `BITSET` uses ~10x less memory than `$php_array[$i] = true`.

### When PHP Arrays Win

**Small datasets (< 1K elements)**: PHP's hash table has lower fixed overhead. The crossover point depends on key density, but Judy's memory advantage typically appears above ~1K elements.

**Mixed-type values with frequent access**: `INT_TO_MIXED` stores `zval *` pointers in JudyL slots, so the zval heap cost is identical to PHP arrays. The `memoryUsage()` value only reflects Judy trie overhead, not the stored values. For small-to-medium datasets, PHP arrays will use less total memory.

**String-keyed lookups**: JudySL's byte-by-byte trie traversal uses more memory per key than PHP's hash table for short, common-prefix-free keys.

### Benchmark Script

Run the memory patterns benchmark to see results on your hardware:

```bash
php examples/benchmarks/judy-bench-memory-patterns.php
```

This script compares Judy vs PHP arrays at 1K, 10K, 100K, and 1M elements across INT_TO_INT, BITSET, and STRING_TO_INT types, including sparse key scenarios.

---

## The optimizeIteration mirror (measured)

New in 2.5.0 and **off by default**. `new Judy($type, optimizeIteration: true)`
mirrors payloads into the key index so ordered reads do not pay a second lookup.
Only `STRING_TO_INT_HASH` and `STRING_TO_INT_ADAPTIVE` honour it.

All figures below are `(measured)` on **php-judy 2.5.0**, from the CI run on the
tagged commit — PHP 8.4.24, Linux x86_64, 500,000 elements, 7 iterations, median
of runs. This is the same run that produced `baselines/latest.json`, so the
contention control applies: run-wide median delta -0.04% against a PHP-array
control of +0.36%.

| Operation | Type | default | optimized | Delta |
| --- | --- | ---: | ---: | ---: |
| `values()` | `STRING_TO_INT_HASH` | 49.77 ms | 27.21 ms | **-45.3%** |
| `toArray()` | `STRING_TO_INT_HASH` | 62.30 ms | 35.76 ms | **-42.6%** |
| `values()` | `STRING_TO_INT_ADAPTIVE` | 60.06 ms | 36.08 ms | **-39.9%** |
| `foreach` | `STRING_TO_INT_HASH` | 56.38 ms | 35.22 ms | **-37.5%** |
| `toArray()` | `STRING_TO_INT_ADAPTIVE` | 75.43 ms | 47.71 ms | **-36.7%** |
| `foreach` | `STRING_TO_INT_ADAPTIVE` | 68.52 ms | 44.98 ms | **-34.4%** |
| `overwrite` | `STRING_TO_INT_ADAPTIVE` | 58.11 ms | 51.13 ms | -12.0% |
| `increment()` | `STRING_TO_INT_HASH` | 39.93 ms | 43.81 ms | **+9.7%** |
| `overwrite` | `STRING_TO_INT_HASH` | 38.80 ms | 42.73 ms | **+10.1%** |

**Read the last two rows before turning this on.** Ordered reads get 34-45%
faster, which is the headline. But `increment()` and overwrite on
`STRING_TO_INT_HASH` get ~10% *slower*, because every write now maintains the
mirror as well. A counter-heavy workload pays that on every operation and
collects none of the read win. The trade is real in both directions, which is
why the flag is opt-in and per-instance rather than a default.

`overwrite` on `STRING_TO_INT_ADAPTIVE` is the one write that improves (-12.0%),
because the adaptive type's short keys already live in a JudyL the mirror does
not duplicate.

Call `isIterationOptimized()` to confirm what actually took effect — every other
type accepts the constructor argument and ignores it.

### Bounded reads

2.5.0's ranged forms (`keys()`, `values()`, `toArray()`, `size()`) are covered by
`api.range.*` in the suite, pinning the two claims the docs make: that
`keys($lo, $hi)` beats `slice($lo, $hi)->keys()`, and that `size($lo, $hi)` beats
`count(keys($lo, $hi))` on string keys.

All figures `(measured)` on **php-judy 2.5.0**, PHP 8.4.24, Linux x86_64,
500,000 elements, 7 iterations, median of runs. The window is the middle 10% of
the key space. Run-wide median delta +0.13% against a PHP-array control of
+0.10% — clean, not contention-inflated.

| Operation | Time | vs the alternative |
| --- | ---: | ---: |
| `keys($lo, $hi)` | 1.03 ms | — |
| `slice($lo, $hi)->keys()` | 3.36 ms | **3.3x slower** |
| PHP-array scan with a bounds test | 25.58 ms | 24.9x slower |
| `size($lo, $hi)` on `STRING_TO_INT` | 4.86 ms | — |
| `count($j->keys($lo, $hi))` | 6.02 ms | **1.24x slower** |

Both documented claims hold. The `slice()` gap is the larger one and the easier
mistake to make: `slice($lo, $hi)->keys()` copies the range into a whole new Judy
array and then traverses that copy, so it pays for the sub-array twice. The
`size()` gap is narrower because both sides walk the same range — the win is that
`size()` allocates nothing, which the wall-clock number understates and which
matters more as the range grows.

Note that on integer keys `size($lo, $hi)` is O(1) — libJudy answers it from the
population cache — so it is recorded as a 1000-call loop (0.73 ms per 1000 calls)
and is *not* compared against `count(keys(...))`. That comparison would be
definitional rather than measured: an O(range) walk cannot beat an O(1) cache
read at any size.

---

## Three-arm benchmark: array vs system libJudy vs bundled libJudy (measured)

Every other measured section on this page answers "is php-judy fast?". This one
answers the two questions users actually ask before adopting or upgrading:

- **What did the libJudy vendoring work buy me?** — arm **B** against arm **C**.
- **Should I use php-judy at all instead of a PHP array?** — arm **A** against
  arm **C**. The honest answer is *sometimes*, and the losing cells are
  published below alongside the winning ones.

| arm | what it is | why it is here |
| --- | --- | --- |
| **A** | PHP native array (`$a[$k]`, `array_*`) | the "why bother" baseline |
| **B** | php-judy linked against a **system** libJudy (`--with-judy=DIR`) | what PECL plus a distro/Homebrew libJudy gives you today |
| **C** | php-judy linked against the **bundled** vendored tree (default build) | what you get after the vendoring work |

Runner: [`scripts/bench-threearm.php`](scripts/bench-threearm.php). Raw JSON and
console output for every run quoted here are committed under
`research/three-arm-benchmark/results/`.

### What "system libJudy" actually means — it is not one thing

A B-vs-C number is meaningless without saying which library arm B linked,
because the distros do not ship the same code:

| platform | package | upstream | Baskins `jp_1Index` fix | how it is built |
| --- | --- | --- | --- | --- |
| Debian 13 / Ubuntu | `libjudy-dev` 1.0.5-5.1 | Judy 1.0.5 | **applied** — `debian/patches/04_fix_undefined_bahavior_during_aggressive_loop_optimizations.patch`, credited to Doug Baskins (SourceForge patch #5) | shared library, dpkg hardening flags (`-fstack-protector-strong`, `_FORTIFY_SOURCE`, PIE) |
| Alpine (musl) | `main/judy` 1.0.5-r1 | Judy 1.0.5 | **not applied** — the APKBUILD lists only the upstream tarball, with no patch files and no `prepare()` patching | shared library |
| macOS Homebrew | `judy` 1.0.5 (bottle) | Judy 1.0.5 | **not applied** — the formula has no `patch` stanza | shared library |

Verified, not assumed: the Debian patch list was read from `apt-get source judy`,
and the Alpine and Homebrew build recipes from their upstream repositories.

This matters directly. Debian's patch 04 fixes the *same* undefined behaviour
that the bundled tree fixes as **P1** (`jp_1Index` written past its declared
width — see [`libjudy/PATCHES.md`](libjudy/PATCHES.md)), by a different edit.
So **on Debian/Ubuntu, B already has the P1-equivalent correctness fix and the
B-vs-C delta does not include it. On Alpine and macOS it does.** A Debian
B-vs-C number is the *conservative* measurement of what vendoring bought.

### What the B-vs-C delta is composed of

B vs C is not "the O-series optimizations in isolation". Holding the extension
source, compiler and PHP identical still leaves five differences bundled
together, and all five are part of what a user actually receives:

1. correctness patches **P2-P7** (and **P1** everywhere except Debian/Ubuntu);
2. **O1** hardware popcount, **O3** word-access node metadata, **O4** string-layer;
3. pinned vendored CFLAGS `-O2 -fno-lto -fno-unroll-loops -mpopcnt` — note that
   `-mpopcnt` is what *activates* O1;
4. **linkage model**: the bundled tree compiles into the extension's own `.so`,
   so Judy calls are direct; arm B calls through the PLT into a shared object
   (42 undefined `Judy*` symbols in the arm-B binary confirm this);
5. the distro's hardening flags, which arm C does not carry.

Both optimizations were confirmed present in C and absent in B at the
instruction level rather than inferred from the build log:

| | arm C (bundled) | arm B (Debian shared libJudy) |
| --- | --- | --- |
| `popcnt` instructions (O1) | **89** | **0** |
| `bswap` instructions (O3) | **985** | 12 (incidental) |

### Methodology, and the traps it exists to avoid

Each of these rules is here because ignoring it has previously produced a wrong
published number in this project.

- **Same source, same toolchain, both arms.** Both `.so` files are built from
  one working tree with one compiler and one PHP, changing only `--with-judy`.
  Comparing against a distro- or PECL-installed `judy.so` is invalid: a prior
  GHA comparison did that and its ~9.4% "win" turned out to bundle toolchain
  provenance differences (FINDINGS §11.10).
- **Arms interleaved, never sequential.** Timing runs one benchmark group at a
  time and alternates arm order every round (ABBA). All statistics are computed
  on per-round *paired ratios*, so machine state during a round divides out.
  Sequential suites produced a wall of false regressions before (#87).
- **A vs C is paired inside one process.** `judy-bench.php` measures its
  PHP-array rows and its Judy rows microseconds apart in the same child, so that
  ratio carries no between-process drift at all.
- **Only genuine PHP-array rows count as arm A.** `judy-bench.php` names rows
  `.judy` and `.php`, but `.php` does not uniformly mean "PHP array" — in
  `api.batch`, `api.setops` (except the BITSET arms) and all of `adv.iter`, the
  `.php` closure builds into and iterates over a **Judy instance**. Those rows
  measure a PHP userland loop against a Judy native method, which is a real but
  *different* question; they are reported separately and never quoted as "PHP
  array vs Judy". The same restriction governs the control (below).
- **Per-residency claim floors, measured in this run rather than assumed.**
  ~3% cache-resident, ~1.3% out-of-cache (FINDINGS §11.10). A cell claims a
  direction only when its **whole** bootstrap CI clears the floor; a point
  estimate past the floor with a straddling CI is reported as null.
- **Two controls.** (a) a PHP-array-only control, whose rows execute no libJudy
  instruction, so any B-vs-C movement on them is pure runner drift and is
  divided back out of every Judy cell; (b) a **C-vs-C rebuild control** — two
  independently linked builds of identical source, offset so no round compares a
  binary against itself. Every C-vs-C cell must read null; cells that do not are
  measuring page/cache layout, not libJudy.
- **Three independent builds per arm, rotated across rounds.** A cell whose
  per-build spread exceeds its own delta is demoted to null: that is binary
  layout, not a library change.
- **The suite's `memory_limit` is a floor, not a ceiling — and it was not
  always.** `judy-bench.php` used to set a flat `ini_set('memory_limit', '2G')`
  at the top of the file, which silently overrode the `-d memory_limit=-1` that
  both `scripts/bench-compare.php` and `scripts/bench-threearm.php` pass every
  child. That made the `core.str` group impossible to run at `--size 6000000`
  under **any** arm: the group materialises four 6M-element PHP arrays before
  the first Judy call and died inside its own fixture builder, so the failure
  was a property of the harness and not a signal about either library. It is a
  second, separate blocker on the out-of-cache row below — alongside the
  memory-safety one that
  [#162](https://github.com/orieg/php-judy/issues/162) closed — and the reason
  an attempt at that row could only cover the integer and bitset paths. The
  script now treats `2G` as a floor it raises a *lower* caller up to, leaves a
  caller who already asked for more (including `-1`) alone, and honours an
  explicit `--memory-limit` that wins outright. Which cap a run used is
  recorded in its JSON as `metadata.memory_limit` and echoed in the console
  banner — **read it there rather than assuming it**, because every run
  published before this change used the unconditional `2G` no matter what its
  driver asked for.
- **Host hygiene gated at load N/2 *and* on co-tenancy.** Load is sampled before,
  between and after every phase; over threshold the run self-marks contaminated
  and every verdict is suppressed.

  **Load average alone is necessary but not sufficient, and this is the single
  most important thing to know before re-running these numbers.** During the
  first attempt at this benchmark, a second, unrelated benchmark campaign was
  running on the same 24-core host. *Both* campaigns individually passed the
  load < N/2 gate — the box sat at load ~2 of a possible 12 — and both were
  corrupted anyway. The other campaign's completely untouched baseline arm
  shifted **2.2x** (69.4 ns/op to 147-172 ns/op on one cell), and its move
  coincided exactly with this run's container starting. A co-resident
  memory-bound benchmark contends for last-level cache and memory bandwidth
  regardless of which cores the scheduler picks, and 30 MB of shared L3 is not
  partitioned by core affinity.

  Worse, **the PHP-array drift control is structurally blind to this**. It read
  +0.36% while every Judy cell moved by tens of percent, because PHP array
  operations are neither pointer-chasing nor DRAM-bound while Judy's descend is
  both — the contention steals exactly what the Judy arms need and barely
  touches the control. A flat control is therefore *not* evidence of a quiet
  host.

  The generalizable rule, and the reason this paragraph is in a benchmark
  document rather than a commit message: **a drift control must share the
  memory-access character of the thing it controls, or it cannot see the failure
  modes that matter.** A control that is cheaper, smaller-working-set, or more
  cache-friendly than the arms under test will certify a run that contention has
  already ruined. The PHP-array control here is still the right instrument for
  detecting *runner* drift — interpreter speed, CPU frequency, process
  startup — and it is retained for that. It is simply not an instrument for
  detecting memory-system contention, and it must not be read as one.

  The runner consequently gates on co-tenancy directly. Load snapshots are taken
  at phase boundaries, when none of the driver's own children are running, so
  any process above 5% CPU at that instant is by construction somebody else's
  work; the run self-marks contaminated when foreign CPU exceeds half a core,
  independent of load average. When re-running, also take the host exclusively —
  the convention on this project's bench host is a `/var/tmp/BENCH_LOCK` file
  naming the agent and phases — and pin the workload
  (`docker run --cpuset-cpus=...` or `taskset`). Pinning does not eliminate
  LLC/bandwidth contention, but it removes scheduler migration as a variable and
  makes a collision detectable rather than silent.

### Memory — the headline, and the least equivocal result

Peak RSS of a child process that builds one structure, median of 3 runs, with a
per-arm empty-process floor subtracted so neither the interpreter nor the loaded
extension is charged to the data. This is measured with `getrusage()` rather
than `memory_get_usage()` on purpose: libJudy allocates through `malloc`,
outside PHP's emalloc heap, so `memory_get_usage()` cannot see most of a Judy
index and badly understates it. **Claim-grade, Linux x86-64 / gcc 14.2.0 /
PHP 8.4.24, exclusive pinned host.**

| workload | n | A: PHP array | B: system | C: bundled | **A/C** | B/C |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `int_to_int` (dense) | 100k | 2.8 MB | 960 KB | 1.1 MB | **2.50x** | 0.83x |
| | 1M | 17.5 MB | 8.4 MB | 8.2 MB | **2.12x** | 1.02x |
| | 8M | 129.6 MB | 66.0 MB | 66.2 MB | **1.96x** | 1.00x |
| `int_sparse` (stride 4099) | 100k | 6.9 MB | 1.3 MB | 1.3 MB | **5.29x** | 1.00x |
| | 1M | 41.1 MB | 12.6 MB | 12.8 MB | **3.22x** | 0.98x |
| | 8M | 310.6 MB | 99.4 MB | 99.6 MB | **3.12x** | 1.00x |
| `int_to_mixed` | 100k | 3.6 MB | 3.8 MB | 3.8 MB | **0.95x** | 1.00x |
| | 1M | 30.4 MB | 38.6 MB | 38.8 MB | **0.78x** | 0.99x |
| | 8M | 244.3 MB | 310.5 MB | 310.5 MB | **0.79x** | 1.00x |
| `string_to_int` | 100k | 7.7 MB | 2.2 MB | 2.2 MB | **3.42x** | 1.00x |
| | 1M | 76.7 MB | 20.4 MB | 20.4 MB | **3.75x** | 1.00x |
| | 8M | 614.9 MB | 185.4 MB | 185.4 MB | **3.32x** | 1.00x |
| `bitset` | 100k | 6.9 MB | 192 KB | 384 KB | **18.50x** | 0.50x |
| | 1M | 41.1 MB | 1.9 MB | 1.9 MB | **21.92x** | 1.00x |
| | 8M | 310.6 MB | 13.5 MB | 13.7 MB | **22.69x** | 0.99x |

`A/C` above 1.00 means the PHP array uses that many times more memory.

Reading this honestly:

- **`Judy::BITSET` is the strongest case in the library**: 22.7x smaller than a
  PHP array at 8M, and the advantage *grows* with scale (18.5x -> 22.7x). At 8M
  the array costs ~40.7 bytes per element and Judy ~1.8.
- **Sparse integer keys 3.1x, string keys 3.3x, dense integer keys 2.0x.** The
  dense-integer case is the weakest because PHP's packed-array representation is
  genuinely good — 17 bytes per element — and Judy's 8.7 only doubles it.
- **`Judy::INT_TO_MIXED` LOSES, and the loss is stable at 0.78-0.79x from 1M
  upward.** Storing arbitrary zvals means Judy holds a pointer to a separately
  allocated zval per element (~40.7 bytes/element) where PHP's hashtable packs
  them (~32). If your values are not integers, php-judy is not a memory win —
  use it for the ordering or the API, not the footprint.
- **B/C is 1.00 nearly everywhere**: the vendoring did **not** change memory.
  That is expected and is a deliberate check — the O-series patches are
  instruction-level changes, and `J1MU`/`JLMU` accounting is byte-identical
  pre/post. The two exceptions are page-granularity artifacts at the smallest
  size (192 KB vs 384 KB is one page rounding, not a regression).

### Where PHP arrays win: per-element scalar operations

The same run, timing rather than memory. **These are the cells where php-judy
loses, and they are the majority.** Only rows whose PHP arm is a genuine PHP
array are included.

| operation | PHP array | php-judy | judy/array | winner |
| --- | ---: | ---: | ---: | --- |
| `bitset` write | 3.00 ms | 5.10 ms | 1.70x | PHP array |
| `int_to_int` write | 3.07 ms | 7.10 ms | 2.31x | PHP array |
| `string_to_int` write | 11.61 ms | 26.11 ms | 2.25x | PHP array |
| `int_to_int` read | 1.07 ms | 3.44 ms | 3.21x | PHP array |
| `string_to_int` read | 2.22 ms | 9.75 ms | 4.39x | PHP array |
| `int_to_int` iterate | 0.91 ms | 6.04 ms | 6.62x | PHP array |
| `string_to_int` iterate | 1.38 ms | 13.83 ms | 10.05x | PHP array |
| `increment` (int keys) | 2.91 ms | 7.19 ms | 2.47x | PHP array |
| `intersect` (bitset) | 4.60 ms | 4.34 ms | **0.94x** | **php-judy** |

**Across the whole suite the PHP array won 42 of 43 comparable cells**, median
4.19x, and the single php-judy win (`intersect` on BITSET) is inside the noise
floor at 0.94x. The cause is not that Judy is a bad data structure — it is that
every php-judy operation crosses the PHP/C boundary, which costs on the order of
16 ns, while `$a[$k]` is an engine opcode. At 300k elements that per-call
overhead dominates anything the C code below it saves.

**So do not adopt php-judy for raw per-element speed.** Adopt it when you need
one of the things the table above cannot show: a much smaller footprint, keys
that come out **sorted** without an `ksort()`, bounded range reads, or set
operations that stay in C.

That last point is where the API earns its place. These rows compare a Judy
native method against a PHP userland loop over the *same Judy object* — not
against a PHP array, so they are a different question, but they show what moving
a whole operation into C is worth:

| operation | PHP loop over Judy | Judy native | ratio |
| --- | ---: | ---: | ---: |
| `populationCount()` | 5.86 ms | ~0.00 ms | O(1) vs O(n) |
| `keys($start, $end)` range read | 7.08 ms | 0.31 ms | **0.04x** |
| `equals()` | 13.78 ms | 3.26 ms | 0.24x |
| `sumValues()` | 5.75 ms | 2.30 ms | 0.40x |
| set `diff` / `xor` (int keys) | 12.75 / 25.55 ms | 5.23 / 10.41 ms | 0.41x |

Ordered iteration deserves its own note, because the comparison above is not
quite fair to Judy: a PHP array *cannot* iterate in key order at all without
sorting first. The 6.62x and 10.05x iterate rows compare Judy's ordered walk
against an array's **insertion-order** walk. If your code needs sorted order,
the array arm must add a `ksort()` — which this benchmark does not charge it
for. Read those rows as "the cost of ordering", not as a like-for-like loss.

### B vs C — what the vendoring actually bought, decomposed

**Claim-grade**, Linux x86-64 / gcc 14.2.0 / PHP 8.4.24, 300k elements
(cache-resident, ~3% floor), 7 interleaved ABBA rounds, 3 independently linked
builds per arm rotated across rounds, exclusive pinned host.

The delivered difference between "PECL plus your distro's libJudy" and "the
default bundled build" is **90 cells faster, 0 slower, 4 null**, against a
PHP-array control reading **+0.00% [-0.12, +0.17]** across 43 rows.

But that number bundles our source patches together with the switch from a
shared distro library to a static in-extension one. Publishing it as "the
vendoring speedup" would misattribute it, so a third arm **S** was built to
split it: pristine Judy 1.0.5 (official tarball, sha256 `d2704089…`), compiled
**static into the extension with the identical pinned CFLAGS**, verified
unpatched at the instruction level (0 `popcnt`, 12 `bswap`, against arm C's 89
and 985). S-vs-C therefore varies *only* the source patches.

| key type | delivered (B→C) | our patches alone (S→C) | share attributable to our patches |
| --- | ---: | ---: | ---: |
| integer / bitset | **-17.7%** (n=33) | **-16.5%** (n=32) | **96.5%** |
| string | **-22.2%** (n=57) | **-11.4%** (n=49) | **39.8%** |

Share is the per-cell median of `ln(S→C) / ln(B→C)` over matched cells. The
residual — shared-library linkage, Debian's hardening flags, and Debian's own
patch 04 — is inferred, not measured directly.

**The honest headline is therefore split by key type:**

- **Integer and bitset paths: the gain is genuinely ours.** 96.5% of the -17.7%
  survives when linkage and flags are held constant. The largest cells are
  integer API operations — `equals` -28.3%, `fromArray` -25.7%, `putAll` -25.4%,
  `intersect` -25.0% — consistent with O1 (hardware popcount) and O3 (word-access
  node metadata) acting on every `JudyL` branch descend.
- **String paths: users get the full -22.2%, but only about 40% of it is our
  source changes.** O4's string-layer work is real and measurable (-11.4%), but
  the majority of the string uplift comes from bundling the library into the
  extension rather than from the patches.

Why the linkage effect is *not* uniform across key types — offered as a
hypothesis, not a measurement, since the bench host has no PMU: `JudySL` and
`JudyHS` are layered on top of `JudyL`, so a single string operation performs
several `JudyL` calls. In arm B each of those crosses the PLT into a shared
object; in arms S and C it is a direct call inside the extension's own binary.
Per-call overhead therefore scales with how many cross-library calls an
operation makes, and string operations make several times more of them than
integer operations do. This is falsifiable: a `-fno-plt` or `-Bsymbolic` build
of the shared arm should close most of the string gap and little of the integer
one. That test has not been run.

The prediction, the falsifier, and the scoring of this decomposition were
**pre-registered before the attribution arm was measured** — see
[`research/three-arm-benchmark/PREREGISTRATION.md`](research/three-arm-benchmark/PREREGISTRATION.md).
Both of the competing predictions recorded there turned out partly wrong, which
is the point of writing them down first.

### The control that makes the above believable

Two builds of **identical source**, independently linked, rotated so no round
ever compares a binary against itself, run through the same suite:

| control | result |
| --- | --- |
| C-vs-C rebuild control, 94 cells | **0 faster, 0 slower, 94 null** |
| PHP-array-only rows, same run | **-0.07% [-0.19, +0.01]** over 43 rows |
| PHP-array-only rows, phase 1 | **+0.00% [-0.12, +0.17]** over 43 rows |
| per-build spread, phase 1 faster cells | 0.07% - 2.15% |

Every cell of the rebuild control is null. That is the apparatus demonstrating
it does not manufacture wins: whatever page and cache layout differ between two
separately linked binaries of the same code, it does not reach the claim floor.
A cell whose per-build spread exceeded its own delta would have been demoted to
null automatically; none in the reported set were.

### Keeping it honest over time: the recurring cross-platform gate

Everything above is a *study*: one campaign, one host, one moment. It says what
the vendoring bought; it says nothing about whether that is still true next
month, or whether it was ever true on arm64 or musl. Those are the two holes the
recurring gate closes.

Runner: [`scripts/bench-gate.php`](scripts/bench-gate.php), driven by
[`.github/workflows/bench-gate.yml`](.github/workflows/bench-gate.yml).

#### It compares ratios, never numbers

A shared CI runner cannot support the measurement above. Absolute milliseconds
on GitHub's runners move tens of percent between one day and the next for
reasons that have nothing to do with this repository, and comparing them
produced a wall of false regressions the last time this project tried it (#87).

So the gate never compares a number across runs. **Every gated quantity is a
ratio of two arms measured in the same interleaved rounds on the same runner**,
compared against the same ratio stored in
[`baselines/arm-ratios.json`](baselines/arm-ratios.json). A runner that is 40%
slower than yesterday's makes both arms 40% slower, and the ratio does not move.
What survives the division is what actually changed in the code.

Three axes, and each sees something the other two cannot:

| axis | what varies between the arms | what only this axis can catch |
| --- | --- | --- |
| **S → C** timing | `libjudy/`'s contents, and nothing else | erosion of the vendored libJudy patches. An extension-level change cancels out of it completely, because both arms share the extension source. |
| **A → C** timing | php-judy against a PHP native array, paired inside **one process** | regressions in the **extension**, which S → C is blind to. A PHP array cannot regress with our code, so it is the invariant reference. |
| **A → C** memory | peak RSS of a child process building one structure | footprint regressions. Nearly deterministic, so it gates at a tighter threshold than either timing axis. |

Together the two timing axes decompose "php-judy got slower" into "the library
did" and "the extension did", which a single axis cannot do.

#### Why arm S, and not a system libJudy

Arm B is not portable and cannot be the recurring comparison arm. Debian ships
`libjudy-dev` **with** the Baskins patch, Alpine and Homebrew ship it pristine,
Windows has no package at all, and the SourceForge download that used to paper
over that was deliberately deleted from CI (#146) and is not coming back. "System
libJudy" is therefore different code on every platform, and it moves when the
distro moves rather than when this repository does — which makes it a poor
regression axis even where it exists.

Arm S has none of those problems. It is *our own* vendored tree reconstructed at
the last commit before the first patch landed, built **static into the extension
with the identical pinned vendor CFLAGS**. No package, no network, available
everywhere, and it holds linkage model, optimization flags, compiler, PHP and
extension source constant so that S → C varies only the libJudy source patches.

Arm B is still built and reported where a package exists (Debian's `libjudy-dev`,
Homebrew's `judy`) — it is what users actually get from PECL on those platforms —
but it is **never gated**, and it is never quoted as "our patches" without the S
decomposition beside it. #161 measured why that matters: the B-vs-S residual is
about 1pp on integer paths and about 11pp on string paths, so attributing the
full B → C delta to our source changes would be wrong by roughly a factor of two
on strings.

#### How arm S is proved unpatched, on every platform

Reconstruction is by `git archive` at a pinned commit, not by downloading a
tarball and not by reverse-applying patches. Two pinned refs, both hard-coded in
[`scripts/bench-arm-s.php`](scripts/bench-arm-s.php) rather than derived — a
reference arm that moves underneath the baseline measures nothing, so bumping
either is a deliberate act:

| ref | what it is |
| --- | --- |
| `0f687cb` | the pristine Judy-1.0.5 import commit |
| `f366fdb` | the last commit touching `libjudy/` **before P1** — pristine upstream plus the build scaffolding (wrapper shims, pre-generated tables) plus P5 |

Verification then runs on the materialized tree, and the **source-level check is
the operative one** because it needs no toolchain and works identically on
Windows:

1. Every upstream `.c`/`.h` file is byte-identical to its blob at the pristine
   import — **21 of 28** files, exactly.
2. The other **7** carry **P5** and only P5 (the LLP64/Windows-x64 constant
   widths), and the verifier fails if the set of modified files is anything other
   than exactly that list. P5 is not optional: `Word_t` must be
   `unsigned __int64` under `_WIN64` or the tree does not compile on Windows at
   all, which would defeat the portability that is arm S's whole point. On LP64
   targets it is a textual change with no semantic effect — `~0UL` and
   `~(Word_t)0` are the same value when `unsigned long` is 8 bytes — so it cannot
   contribute to an S → C delta on Linux or macOS.
3. No file that carries a post-`f366fdb` patch at `HEAD` may be byte-identical to
   `HEAD` in the arm-S tree. This fails differently from check 1 — a mis-set
   pristine ref would make check 1 vacuously pass and this one would still catch
   it — and it keeps working as the patch series grows, which a hand-written list
   of grep fingerprints would not.
4. `__POPCNT__` and `__builtin_bswap64` must be textually absent, so a reader can
   re-run the check with `grep` and no script.

`JudyCommon/JudyNoInline.c` is a P7 *addition* and so does not exist at
`f366fdb`, but `config.m4` lists it unconditionally; the script synthesizes an
empty translation unit for it. That is not a fudge — P7's real file is wrapped in
`#ifdef JU_NOINLINE`, which no benchmark or production build defines, so both
compile to the same empty object and the compiled-unit list stays identical
across the two arms.

An **instruction census** is kept as an independent cross-check, and it is
architecture-aware rather than assuming x86 mnemonics:

| architecture | O1 lowers to | O3 lowers to | arm S | arm C |
| --- | --- | --- | ---: | ---: |
| x86-64 (gcc 14.2, `-mpopcnt`) | `popcnt` | `bswap` | 0 / 12 | 89 / 985 |
| arm64 (Apple clang, base ISA) | `cnt` | `rev` | 0 / 32 | 88 / 673 |

The O1 count reads exactly **0** in arm S on both architectures, which is the
sharp test; the O3 mnemonic has incidental non-O3 uses and is corroboration only.

#### The control, and why the PHP-array one is not enough

The PHP-array drift control is retained, and it is the right instrument for what
it measures — interpreter speed, CPU frequency, process startup. It is **not** an
instrument for the failure mode that matters here, and this document already
explains why at length: PHP array operations are neither pointer-chasing nor
DRAM-bound while Judy's descend is both, so a co-resident tenant stealing
last-level cache and memory bandwidth takes exactly what the Judy arms need and
barely touches the control. Measured once on a 24-core host: an untouched
baseline arm moved **2.2x** while the array control read **+0.36%**.

The gate's load-bearing control is therefore **C1 vs C2** — two independently
linked builds of *identical* source, interleaved into the same rounds as
everything else. It **is** Judy, so it has the right memory-access character by
construction, and every one of its cells must read null.

It does double duty. Its measured scatter is that run's own empirical noise
floor, and the applied threshold is raised to it whenever it exceeds the stored
offline floor. **A contaminated run therefore widens its own gate and cannot cry
wolf** — and it cannot pass quietly either, because the widened threshold and the
control that caused it are both printed in the run's summary.

Windows is the one platform without it: a second MSVC build roughly doubles an
already long job, so it runs one build per arm, `rebuild_control_available` reads
`false` in its run JSON, and its threshold falls back to the offline floor alone.
That is recorded rather than quietly equated with the other platforms.

### Confidence tiers, and what is NOT measured

There are now three tiers on this page and they are not interchangeable.

| tier | what it means | who produces it |
| --- | --- | --- |
| **claim-grade** | absolute numbers, exclusive pinned host, hygiene clean, controls inside the claim floor. Quotable as "php-judy is X% faster". | `bench-threearm.php` on the dedicated bench host |
| **ci-relative** | *ratios* of arms measured in the same interleaved rounds on a shared runner, with the run's own rebuild control stating how far a cell can move for no reason. Quotable as "the patches deliver about X% on this platform" and as "this has not regressed". **Not** quotable as an absolute number. | `bench-gate.php` in `bench-gate.yml` |
| **directional** | a single measurement from a host that failed its own hygiene gate. Useful as a sanity check, not as evidence. | ad-hoc runs |

| platform | toolchain | S → C timing | A → C timing | memory | tier |
| --- | --- | --- | --- | --- | --- |
| Linux x86-64, honeycomb (dedicated) | gcc 14.2.0, PHP 8.4.24, Debian 13 | measured | measured | measured | **claim-grade** |
| Linux x86-64 glibc, GitHub runner | gcc 13.3.0, PHP 8.4.24, Ubuntu 24.04 | measured | measured | measured | **ci-relative** |
| Linux x86-64 **musl**, Alpine container | gcc 15.2.0, PHP 8.4.24 | measured | measured | measured | **ci-relative** |
| **macOS arm64**, GitHub runner | Apple clang 21.0.0, PHP 8.4.24 | measured | measured | measured | **ci-relative** |
| macOS arm64, local workstation | Apple clang 21, PHP 8.5.8, Homebrew | directional | directional | measured | **directional** |
| Windows x64 | MSVC | see below | see below | see below | see below |
| Linux x86-64, **out-of-cache** (>=6M) | gcc 14.2.0 | not measured | not measured | measured (8M rows above) | **not measured** |

#### What the gate measured on each platform

Pooled medians over the 51-52 comparable cells, **4 gate runs per platform
across 2 distinct runner instances**, `--size 300000` (cache-resident), 5
interleaved rounds, 2 independently linked builds per arm. Recorded in
[`baselines/arm-ratios.json`](baselines/arm-ratios.json). **These are
ci-relative ratios, not claim-grade absolutes.**

| platform | toolchain | S → C median | A → C median | `int_sparse` mem A/C | `string_to_int` mem A/C |
| --- | --- | ---: | ---: | ---: | ---: |
| Linux glibc x86-64 | gcc 13.3.0 | **0.870** | 4.79x | 3.34x | 3.81x |
| Linux musl x86-64 | gcc 15.2.0 | **0.906** | 6.49x | 3.34x | 3.74x |
| macOS arm64 | Apple clang 21.0.0 | **0.910** | 6.50x | 3.21x | 3.42x |
| *(dedicated host, claim-grade, for comparison)* | *gcc 14.2.0* | *0.835 int / 0.886 string* | *4.19x median* | *3.12x @8M* | *3.32x @8M* |

Four things worth saying about that table, and the last one is a caveat rather
than a finding:

- **musl is not a problem.** S → C is 0.906 on musl against 0.870 on glibc, and
  the memory ratios are within a percent of each other. This was the open
  question that made Alpine worth measuring rather than box-ticking: musl's
  allocator is not glibc's and Judy is allocation-heavy — a prior analysis
  attributed ~24% of DRAM traffic to glibc's allocator serving Judy's node churn
  — so the vendored patches could plausibly have behaved differently there. They
  do not, on either axis.
- **arm64 gets a smaller but real share of the gain.** This is the first time any
  merged optimization in this project has been timed on Apple clang or on a
  non-x86 instruction set. A smaller effect is the expected direction — O1's
  whole premise is that x86-64 had no hardware popcount arm while arm64's `cnt`
  is base ISA, so arm S is less handicapped there — but the size of the gap had
  never been measured and was not predictable from the source.
- **The shared runners read a smaller effect than the dedicated host**, in the
  same direction on every platform. That is what a noisier measurement of the
  same underlying quantity looks like, and it is why these rows are labelled
  ci-relative rather than quoted as absolutes.
- **Four runs is a thin pooling.** The per-cell floors below are estimated from
  4 samples across 2 runners; a per-cell worst-case from 4 samples is a weak
  estimator, and these numbers should tighten as scheduled runs accumulate.
  Re-deriving is a one-line command and belongs in a dedicated baseline PR.

#### The gate's thresholds, and how they were derived

Derived, not chosen — and the derivation changed the design once it was run.

`bench-gate.php --derive` runs the gate repeatedly and measures how far each
cell's ratio moves when the measured code did not change. **The first derivation
was taken from repeats inside one job and put the S → C floor at 3.5-4%, close
to the dedicated host's claim floor. That number was wrong for this purpose.**
The gate compares against a baseline recorded on a *different runner instance on
a different day*, and cross-runner drift turned out to be three to five times
within-runner drift. Deriving from within-job repeats would have shipped a gate
that fired on runner-to-runner variation. The floors below come from 4 runs
spanning 2 runner instances, and `--derive` records `distinct_hosts` in the
baseline so a future reader can see whether a floor was derived across runners
or only within one.

That honest derivation then broke the original per-axis design:

| axis | median cell drift | p95 | worst cell | a single axis floor would be |
| --- | ---: | ---: | ---: | ---: |
| glibc S → C | 1.3% | 14.6% | 25.7% (`core.bitset.write`) | 18.5% |
| glibc A → C | 8.1% | 77.7% | **188.2%** (`core.string_to_int_adaptive.free`) | **97.5%** |
| glibc memory | 0.9% | 4.6% | 4.6% | 6.0% |

A 97.5% threshold catches nothing. It is set entirely by a handful of
destructor-timing cells whose cost is dominated by allocator return behaviour
rather than by anything this project controls, while the median cell on the same
axis reproduces to 8%.

**So the floor is per cell — but the pooled axis floor is a lower bound on every
cell, not a fallback.** That second half was learned the hard way. The first
attempt let each cell's floor be its own worst observed drift x 1.25, free to go
below the axis number, and it **cried wolf on the very first gated run**: five
S → C cells on glibc were reported as regressions against a baseline derived
from that same code, having moved 2.5-12.8% against per-cell floors of 2-9.5%.

The reason is not a bug, it is the estimator. **Cross-runner drift is a
systematic per-runner offset, not random per-measurement noise** — consistent
within a job, different between jobs. A per-cell maximum over four samples badly
understates it, and every one of the five flagged cells had moved by less than
the axis p95 of 14.6%: the pooled statistic, over ~50 cells, had the sample size
to see what the per-cell one did not. So a cell's floor may be *wider* than the
axis floor and never narrower, until there are enough runs across enough distinct
runners (currently ≥8 runs on ≥4 runners) for a per-cell estimate to mean
anything. The baseline records which regime it was built in
(`axis_floor_is_lower_bound`), so the constraint lifts itself as scheduled runs
accumulate rather than needing anyone to remember.

Above a ceiling (50% timing, 15% memory) a cell is reported but not gated at all,
because carrying a "190% threshold" in the baseline would suggest coverage that
does not exist. Nothing is hand-excluded; the data decides, and every cell's
floor, its worst observed drift and whether it is gated are written into the
baseline where a reviewer can audit them.

| platform | axis | cells gated | per-cell floor (min / median / max) |
| --- | --- | ---: | --- |
| Linux glibc x86-64 | S → C | 51 / 51 | 18.5% / **18.5%** / 38.5% |
| | A → C | **0 / 42** | — (axis floor 97.5% exceeds the 50% ceiling) |
| | memory | 3 / 3 | 6% / **6%** / 7% |
| Linux musl x86-64 | S → C | 52 / 52 | 11% / **11%** / 19.5% |
| | A → C | 33 / 43 | 46.5% / 46.5% / 46.5% |
| | memory | 3 / 3 | 4% / **4%** / 4.5% |
| macOS arm64 | S → C | 51 / 51 | 16% / **16%** / 26% |
| | A → C | 42 / 42 | 16% / 16% / 34% |
| | memory | 3 / 3 | 10% / **10%** / 12% |

**Held-out validation.** Applying these floors to two later gate runs per
platform that were *not* part of the derivation, with the measured code
unchanged, gives **0 flags over 300 evaluated cells** — against 5 from the
floors they replaced. That is the number that matters for a gate: not how tight
it is, but whether it is silent when nothing changed.

Reading that honestly:

- **This is a coarse gate on timing, and pretending otherwise would be the
  defect.** At an 11-18.5% S → C floor it catches gross breakage — an
  optimization silently compiled out, a patch reverted, a flag lost from the
  vendored CFLAGS, all of which are 15-25% effects — and it will not see
  anything subtler. The sharp instrument for small effects remains
  `bench-threearm.php` on the dedicated host, where the claim floor is ~3%. What
  this buys that the dedicated host cannot is *every platform, every week,
  without anyone scheduling a box*.
- **S → C is nonetheless the axis it is best at**, and it is the one that matters
  for the vendored tree: every cell on every platform is gateable, on all three
  platforms.
- **A → C is not usable as a gate on glibc at all** — 0 of 42 cells, because its
  axis floor of 97.5% exceeds the ceiling. The `.free` cells drift up to 188%
  between runners and drag the pooled statistic with them. It is still measured
  and reported on every platform, and it does gate on musl (33/43) and macOS
  (42/42). Recording that glibc's A → C coverage is zero is more useful than
  quietly carrying a threshold that could never fire.
- **Memory gates at 4-10%** and is the axis carrying php-judy's least equivocal
  claim, so it is worth more than its width suggests: a footprint regression is
  usually a representation change, which moves RSS by tens of percent.
- **These floors should tighten.** They are derived from 4 runs on 2 runners; at
  ≥8 runs on ≥4 runners the per-cell floors are allowed below the pooled one,
  and the baseline flips itself out of the conservative regime.
- **A run's own C-vs-C rebuild control can only widen a threshold, never narrow
  one.** Measured on these runs: p90 per-cell deviation 1.9-3.1% on Linux and
  5.1-6.4% on macOS, against maxima of 3.1-20.7%. On a normal run the stored
  per-cell floor governs; on a bad day the control takes over and says so.
- **Small memory cells are measured but never gated.** Peak RSS is
  page-quantised, so a structure under 4 MiB moves several percent between
  identical runs. `bitset` at 1M keys is ~1.9 MB on glibc and ~0.6 MB on musl,
  and it is excluded on both.

One consequence worth stating plainly: **the same `bitset` cell reads about 23x
on glibc and about 99x on musl** — and it moved between 26x and 158x across the
four musl runs. That is not a Judy difference; the two allocators retain a
different number of pages for a sub-megabyte structure, and at that size the
per-arm process-floor subtraction is comparing small differences of large
numbers. It is exactly the kind of figure that would mislead if quoted, which is
why it sits below the gating floor and is not in the headline table above.

#### Still not measured

- **Out-of-cache timing is still not measured, but the memory-safety signal that
  blocked it is resolved.** The intended 6M run aborted when arm B terminated
  with SIGSEGV in the `core.int` group, and at the time this section could not
  say whether the fault lay in the extension, the benchmark script, or libJudy.
  It is now established: the fault is **php-judy's own PHP-facing layer**, not
  libJudy and not the benchmark. `judy_free_array_internal()` walked the
  container calling `zval_ptr_dtor()` + `efree()` on each stored zval while the
  freed pointers stayed reachable in the container; once the GC root buffer
  filled, `gc_collect_cycles()` ran synchronously inside that loop and
  `judy_object_get_gc()` re-walked the half-freed container. Use-after-free,
  surfacing as `zend_mm_heap corrupted` or SIGSEGV. Tracked as
  [#162](https://github.com/orieg/php-judy/issues/162), fixed in `dcc368e`
  (unlink the container before the destructive walk), covered by
  `tests/regression_gc_teardown_reentrancy_001.phpt`.

  That the crash reproduced under **both** arms was the correct reading and
  remains so: it is explicitly **not** a bundled-versus-system robustness claim,
  and nothing in this section should be read as one. The bundled-vs-system
  comparison was never the variable.

  Post-fix verification, one tree per arm built from the two commits and driven
  by the identical `judy-bench.php` (`--group core.int --iterations 1`). The
  pre-fix column at 3M/6M on linux/amd64 also reproduces the original triage
  above, which covered arms **B** and **C**; the post-fix runs below are arm
  **C** (bundled) only.

  | check | pre-fix (`172e489`) | post-fix (`dcc368e`) |
  | --- | --- | --- |
  | macOS arm64, plain 6M `INT_TO_INT` insert loop, both arms | clean | clean |
  | linux/amd64 (`php:8.4-cli`, gcc 14.2.0), `core.int` n=3M | `zend_mm_heap corrupted` (rc=134) | clean (rc=0) |
  | linux/amd64, `core.int` n=6M | `zend_mm_heap corrupted` (rc=134) | clean (rc=0) |
  | linux/amd64, `regression_gc_teardown_reentrancy_001` body | `zend_mm_heap corrupted` (rc=134) | clean (rc=0) |
  | macOS arm64 (PHP 8.5.8), `core.int` n=3M | clean | clean |
  | macOS arm64, `core.int` n=6M | `zend_mm_heap corrupted` (rc=134) | clean (rc=0) |

  The insert-only loop stayed clean throughout because it never puts the Judy
  object in the GC root buffer; `judy-bench.php` does, via `count()` and the
  closures it hands the container to. macOS needed 6M to fault where linux/amd64
  faulted at 3M — an allocator difference, not a different bug. The `2G`
  `memory_limit` that `judy-bench.php` set unconditionally at the time was a
  candidate mechanism (a bailout unwinding through an in-progress Judy write)
  and is ruled out: the post-fix 6M runs complete under that same cap without a
  fatal. Those runs are `core.int`, whose fixtures fit inside `2G`; the cap has
  since become a floor rather than an override (methodology above), which does
  not affect this result either way.

  **What this unblocks and what it does not.** The out-of-cache (>=6M) `core.int`
  cell now runs to completion, so the arm can be scheduled; the slot in the tier
  table stays `not measured` until it is actually run on an exclusively-held
  honeycomb. Nothing above is a timing measurement — the runs in the table are
  crash reproductions on a shared laptop and assert nothing about performance.
  The 8M rows in the memory table were always unaffected: those run in their own
  child processes and complete cleanly.

  For the gate specifically: the slot is wired and needs no rework. Raise
  `BENCH_SIZE` past the driver's `--dram-size` in
  `.github/workflows/bench-gate.yml` and the out-of-cache cells become a fourth
  set of baseline entries. It is left switched off here only because a new
  residency needs its own derived floor, and deriving one belongs in the
  dedicated commit that writes the baseline rather than in the PR that builds
  the gate.
- **Windows.** The job exists and builds both arms through the same
  `php-windows-builder` action from one tree with `libjudy/` swapped between
  invocations — which is only possible because arm S needs no package. It runs
  one build per arm rather than two, so `rebuild_control_available` is `false`
  there and its threshold falls back to the offline floor with no run-local
  noise measurement behind it. Treat Windows as the lowest tier on this page
  until it has accumulated enough scheduled runs to derive its own floor.

### Reproducing this

```sh
# Build BOTH arms from ONE tree with ONE toolchain, changing only --with-judy.
phpize && ./configure --with-judy=/usr    && make && cp modules/judy.so judy-system.so
make clean && phpize --clean
phpize && ./configure --with-judy=bundled && make && cp modules/judy.so judy-bundled.so

php scripts/bench-threearm.php \
  --system-so judy-system.so --bundled-so judy-bundled.so \
  --rounds 7 --size 300000 --assert-same-source \
  --system-provenance "$(. /etc/os-release; echo "$PRETTY_NAME") $(dpkg-query -W -f='${Version}' libjudy-dev 2>/dev/null)" \
  --out bench-threearm.json
```

Pass `--system-so` / `--bundled-so` more than once to rotate independent builds.
Passing two *bundled* builds turns the run into the C-vs-C rebuild control.
`--skip-timing` runs only the memory matrix, which is what makes a contended
host still useful. Raw JSON and console output for every run quoted above are
committed under `research/three-arm-benchmark/results/`.

**Before trusting a re-run**: take the host exclusively, pin the workload, and
read the `hygiene` block in the JSON. A run that self-marks `contaminated`
asserts nothing, and — as the co-tenancy note above explains — a flat PHP-array
control is not by itself evidence that the host was quiet.

#### Reproducing the gate, on any platform

The gate needs no system libJudy and no dedicated host, which is the whole point
of arm S. One script builds every arm from one tree with one toolchain:

```sh
# Two independently linked builds of each of arms S and C. Two, not one:
# they are rotated across rounds, and C1-vs-C2 is the rebuild control that
# measures how far a cell can move for no reason on this machine today.
./tools/bench-gate/build-arms.sh /tmp/arms 2

php scripts/bench-gate.php \
  --arm C=/tmp/arms/judy-C-1.so --arm C=/tmp/arms/judy-C-2.so \
  --arm S=/tmp/arms/judy-S-1.so --arm S=/tmp/arms/judy-S-2.so \
  --rounds 5 --size 300000 \
  --baseline baselines/arm-ratios.json --gate \
  --out gate.json
```

`build-arms.sh` writes `arm-s-manifest.json` beside the objects; that is the
verification record, and a run whose manifest does not say `UNPATCHED` is
comparing against something other than pristine Judy. To check by hand:

```sh
php scripts/bench-arm-s.php --dest /tmp/arm-s --manifest /tmp/arm-s.json
php scripts/bench-arm-s.php --verify-so /tmp/arms/judy-S-1.so   # instruction census
php scripts/bench-arm-s.php --verify-so /tmp/arms/judy-C-1.so   # ... against arm C's
```

Deriving a platform's threshold floors, which is what makes them defensible
rather than guessed — several runs of the **same commit**, then:

```sh
php scripts/bench-gate.php --derive gate-1.json,gate-2.json,gate-3.json
```

`--derive` refuses a set spanning more than one commit or more than one
platform: a floor derived with the code moving underneath it measures real
change, not noise. Add `--update-baseline baselines/arm-ratios.json` to write
the result — **in a dedicated commit, never inside a feature PR**, the same rule
`baselines/latest.json` already works to. The two baseline files are different
instruments and are not interchangeable: `latest.json` holds absolute
milliseconds for the release-over-release `bench-compare.php` run, `arm-ratios.json`
holds per-platform within-run arm ratios for this gate.

---

## Running the Benchmarks

### **Quick Benchmarks**
```bash
# Run comprehensive benchmark suite (recommended)
php examples/benchmarks/run_comprehensive_benchmarks.php

# Run individual benchmark phases
php examples/benchmarks/benchmark_ordered_data.php
php examples/benchmarks/benchmark_range_queries.php
php examples/benchmarks/benchmark_real_world_patterns.php

# Run batch operations and increment benchmarks
php examples/benchmarks/judy-bench-batch-operations.php

# Run memory usage pattern comparison
php examples/benchmarks/judy-bench-memory-patterns.php

# Compare against the real alternatives (APCu, SplFixedArray, sorted arrays)
php examples/benchmarks/judy-bench-alternatives.php
php -d apc.enable_cli=1 -d apc.shm_size=2048M \
    examples/benchmarks/judy-bench-alternatives.php   # includes APCu rows
```

**Note**: All benchmarks use proper Iterator interface methods and run without deprecated warnings.

### **Legacy Benchmarks**
```bash
# Run original benchmarks (single iteration)
php examples/benchmarks/run-benchmarks.php

# Run robust benchmarks (multiple iterations)
php examples/benchmarks/run-benchmarks-robust.php
```

---

## References

Our methodology and insights are informed by the [Rusty Russell benchmark comparison](https://rusty.ozlabs.org/2010/11/08/hashtables-vs-judy-arrays-round-1.html) between hashtables and Judy arrays, which demonstrates Judy's strengths in ordered access patterns and memory efficiency.

**Describes**: php-judy 2.5.2
**Figures measured on**: 2.4.2, verified unchanged on 2.5.0 (0 regressions, run-wide
median -0.04%; see Benchmarking Environment)
**Last Updated**: August 2026
