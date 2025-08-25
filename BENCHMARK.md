# PHP Judy Performance Benchmarks

This document provides a detailed performance and memory usage comparison between the `php-judy` extension and native PHP arrays. The goal is to provide clear, data-driven guidance on when to use each data structure.

## Benchmark Methodology

The benchmarks were executed using a script that tests two realistic, large-scale scenarios:

1.  **Sparse Integer Keys:** Simulates use cases like storing data by non-sequential IDs (e.g., database primary keys). This is the ideal use case for Judy arrays.
2.  **Random String Keys:** Simulates common use cases like associative arrays, caches, or dictionaries where keys are strings.

For each scenario, the following operations were measured:
*   **Write Time:** The time taken to populate the data structure.
*   **Read Time:** The time taken to perform random-access reads of every key.
*   **Memory Footprint:** The total memory consumed by the data structure.

### Memory Measurement Methodology

**PHP Arrays:** Memory usage is measured using PHP's `memory_get_usage(true)` function, which provides accurate real memory consumption.

**Judy Arrays:** 
- **Integer-based Judy arrays** use the native `memoryUsage()` method for precise measurements
- **String-based Judy arrays** use estimated memory based on size and average key length (since `memoryUsage()` returns NULL for string keys)

All tests were performed using the `php:8.1-cli` Docker image to ensure a consistent and isolated environment. The full benchmark scripts can be found in `examples/run-benchmarks.php` and individual benchmark files in the `examples/` directory.

**Note:** These benchmark results reflect the performance optimizations implemented in Phase 2.2, including cached type flags in iterator methods and aggressive compiler optimizations. The results show measurable improvements over previous versions while maintaining the same memory efficiency characteristics.

## Benchmark Results

The following tables summarize the results for datasets ranging from 100,000 to 10,000,000 elements.

---

### **Scenario 1: Sparse Integer Keys**

| Elements     | Subject   | Write Time | Read Time | Memory Footprint  |
|--------------|-----------|------------|-----------|-------------------|
| **100k**     | PHP Array | 0.0048s    | 0.0028s   | 7 mb              |
|              | Judy      | 0.0191s    | 0.0150s   | **1.84 mb**       |
| **500k**     | PHP Array | 0.0297s    | 0.0349s   | 20 mb             |
|              | Judy      | 0.0700s    | 0.0814s   | **9.19 mb**       |
| **1M**       | PHP Array | 0.1013s    | 0.0921s   | 40 mb             |
|              | Judy      | 0.3012s    | 0.2775s   | **18.39 mb**      |
| **10M**      | PHP Array | 1.6572s    | 1.1902s   | 640 mb            |
|              | Judy      | 5.0023s    | 11.1267s  | **183.6 mb**      |

---

### **Scenario 2: Random String Keys**

| Elements     | Subject   | Write Time | Read Time | Memory Footprint  |
|--------------|-----------|------------|-----------|-------------------|
| **100k**     | PHP Array | 0.0048s    | 0.0031s   | 5 mb              |
|              | Judy      | 0.0310s    | 0.0184s   | **3.05 mb**       |
| **500k**     | PHP Array | 0.0446s    | 0.0416s   | 20 mb             |
|              | Judy      | 0.1666s    | 0.2708s   | **15.26 mb**      |
| **1M**       | PHP Array | 0.1458s    | 0.1119s   | 40 mb             |
|              | Judy      | 0.4053s    | 0.4598s   | **30.52 mb**      |
| **10M**      | PHP Array | 2.7764s    | 1.4480s   | 640 mb            |
|              | Judy      | 7.7160s    | 7.9496s   | **305.18 mb**     |

---

## Key Findings

### Memory Efficiency
- **Judy arrays excel at memory efficiency** for sparse integer data, offering **over 2x memory savings (up to 3.8x in tests)** compared to PHP arrays
- **String-based Judy arrays** show moderate memory savings but with performance trade-offs
- **PHP arrays** are more memory-intensive but offer better performance for most workloads

### Performance Characteristics
- **PHP arrays** are generally faster for both read and write operations
- **Judy arrays** show improved performance with Phase 2.2 optimizations while maintaining memory benefits
- **String-based Judy arrays** have higher performance overhead compared to integer-based ones, but recent optimizations have reduced this gap

## Recommendations

Based on these results, the guidance is clear:

1.  **Use `Judy` for Large-Scale, Sparse Integer Data:** If an application's primary bottleneck is **memory consumption**, and it uses **large arrays of non-sequential (sparse) integers**, the Judy extension offers significant advantages. The extension's primary benefit is its memory efficiency, offering up to 3.8x savings at scale. This can be critical in preventing memory exhaustion and may help reduce server costs.

2.  **Use Native PHP Arrays for Most Other Use Cases:** For most other scenarios, especially those involving **string-based keys** or performance-critical workloads where memory is less of a concern, native PHP arrays are often the more performant choice. PHP's native hash table is highly optimized for these workloads and delivers excellent performance.

3.  **Consider Judy for Memory-Constrained Environments:** In environments where memory is limited (e.g., shared hosting, containers with memory limits), Judy arrays can help stay within memory constraints while maintaining functionality.

## Running the Benchmarks

### Quick Benchmarks (Single Run)
For quick performance checks:

```bash
# Run all benchmarks (single iteration)
php examples/run-benchmarks.php

# Run individual benchmark types
php examples/judy-bench-int_to_int.php
php examples/judy-bench-string_to_int.php
php examples/judy-bench-bitset.php
```

### Robust Benchmarks (Multiple Iterations)
For statistically significant results with variance analysis:

```bash
# Run robust benchmarks with 20 iterations (default)
php examples/run-benchmarks-robust.php

# Run with custom number of iterations
php examples/run-benchmarks-robust.php 50

# Run with custom memory limit
php examples/run-benchmarks-robust.php 20 8G

# Generate JSON output for analysis
php examples/run-benchmarks-robust.php 20 4G json

# Generate both text and JSON output
php examples/run-benchmarks-robust.php 20 4G both
```

### Robust Benchmark Features

The robust benchmark system provides:

- **Multiple Iterations**: Configurable number of runs (default: 20)
- **Statistical Measures**: Min, max, median, mean, 95th percentile, 99th percentile, standard deviation
- **Variance Analysis**: Understand performance consistency and outliers
- **JSON Output**: Machine-readable results for further analysis
- **Memory Tracking**: Accurate memory usage measurements across iterations
- **Garbage Collection**: Proper cleanup between iterations

### Benchmark Methodology

**Single-Run Benchmarks:**
- Quick performance assessment
- Suitable for development and testing
- Results may vary due to system load and caching

**Robust Benchmarks:**
- Statistically significant results
- Accounts for system variance and outliers
- Provides confidence intervals through percentiles
- Recommended for production performance analysis

The benchmarks use Docker for consistent results across different environments.
