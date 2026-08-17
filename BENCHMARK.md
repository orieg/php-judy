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
- **PHP Version**: 8.x with Judy extension 2.4.2
- **Test Methodology**: Multiple iterations with statistical analysis (min/max/median/percentiles)
- **Memory Measurement**: Using `memory_get_usage(true)` and `Judy::memoryUsage()`

*Note: Results may vary based on hardware, system load, and PHP configuration. All benchmarks use the same Docker environment for consistency.*

## 🎯 **Quick Decision Guide**

> Choosing between Judy and **APCu, `SplFixedArray`, or a sorted array** rather
> than a PHP array? Skip to [Versus the Alternatives](#versus-the-alternatives-apcu-splfixedarray-sorted-arrays).

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

**Judy Extension Version**: 2.4.2
**Last Updated**: August 2026
