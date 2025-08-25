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

## Benchmark Results

The following tables summarize the results for datasets ranging from 100,000 to 10,000,000 elements.

---

### **Scenario 1: Sparse Integer Keys**

| Elements     | Subject   | Write Time | Read Time | Memory Footprint  |
|--------------|-----------|------------|-----------|-------------------|
| **100k**     | PHP Array | 0.0069s    | 0.0076s   | 7 mb              |
|              | Judy      | 0.0154s    | 0.0131s   | **1.84 mb**       |
| **500k**     | PHP Array | 0.0481s    | 0.0627s   | 20 mb             |
|              | Judy      | 0.1135s    | 0.1738s   | **9.18 mb**       |
| **1M**       | PHP Array | 0.1366s    | 0.1242s   | 40 mb             |
|              | Judy      | 0.3346s    | 0.3068s   | **18.39 mb**      |
| **10M**      | PHP Array | 2.5086s    | 1.6139s   | 640 mb            |
|              | Judy      | 6.0602s    | 14.5929s  | **183.64 mb**     |

---

### **Scenario 2: Random String Keys**

| Elements     | Subject   | Write Time | Read Time | Memory Footprint  |
|--------------|-----------|------------|-----------|-------------------|
| **100k**     | PHP Array | 0.0134s    | 0.0072s   | 5 mb              |
|              | Judy      | 0.0246s    | 0.0188s   | **3.05 mb**       |
| **500k**     | PHP Array | 0.0515s    | 0.0522s   | 20 mb             |
|              | Judy      | 0.3018s    | 0.3146s   | **15.26 mb**      |
| **1M**       | PHP Array | 0.1596s    | 0.1216s   | 40 mb             |
|              | Judy      | 0.5051s    | 0.5402s   | **30.52 mb**      |
| **10M**      | PHP Array | 4.7823s    | 2.0904s   | 640 mb            |
|              | Judy      | 9.1781s    | 8.4397s   | **305.18 mb**     |

---

## Key Findings

### Memory Efficiency
- **Judy arrays excel at memory efficiency** for sparse integer data, offering **over 2x memory savings (up to 3.8x in tests)** compared to PHP arrays
- **String-based Judy arrays** show moderate memory savings but with performance trade-offs
- **PHP arrays** are more memory-intensive but offer better performance for most workloads

### Performance Characteristics
- **PHP arrays** are generally faster for both read and write operations
- **Judy arrays** show slower performance but with significant memory benefits
- **String-based Judy arrays** have higher performance overhead compared to integer-based ones

## Recommendations

Based on these results, the guidance is clear:

1.  **Use `Judy` for Large-Scale, Sparse Integer Data:** If an application's primary bottleneck is **memory consumption**, and it uses **large arrays of non-sequential (sparse) integers**, the Judy extension offers significant advantages. The extension's primary benefit is its memory efficiency, offering over 3x savings at scale. This can be critical in preventing memory exhaustion and may help reduce server costs.

2.  **Use Native PHP Arrays for Most Other Use Cases:** For most other scenarios, especially those involving **string-based keys** or performance-critical workloads where memory is less of a concern, native PHP arrays are often the more performant choice. PHP's native hash table is highly optimized for these workloads and delivers excellent performance.

3.  **Consider Judy for Memory-Constrained Environments:** In environments where memory is limited (e.g., shared hosting, containers with memory limits), Judy arrays can help stay within memory constraints while maintaining functionality.

## Running the Benchmarks

To run the benchmarks yourself:

```bash
# Run all benchmarks
php examples/run-benchmarks.php

# Run individual benchmark types
php examples/judy-bench-int_to_int.php
php examples/judy-bench-string_to_int.php
php examples/judy-bench-bitset.php
```

The benchmarks use Docker for consistent results across different environments.
