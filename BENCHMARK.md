# PHP Judy Performance Benchmarks

This document provides a detailed performance and memory usage comparison between the `php-judy` extension and native PHP arrays. The goal is to provide clear, data-driven guidance on when to use each data structure.

## Benchmark Methodology

The benchmarks were executed using a script that tests two realistic, large-scale scenarios:

1.  **Sparse Integer Keys:** Simulates use cases like storing data by non-sequential IDs (e.g., database primary keys). This is the ideal use case for Judy arrays.
2.  **Random String Keys:** Simulates common use cases like associative arrays, caches, or dictionaries where keys are strings.

For each scenario, the following operations were measured:
*   **Write Time:** The time taken to populate the data structure.
*   **Read Time:** The time taken to perform random-access reads of every key.
*   **Mixed Time:** The time taken for a mixed workload of 50% reads and 50% updates.
*   **Memory Footprint:** The total memory consumed by the data structure, as reported by the operating system's Resident Set Size (RSS) for the highest level of accuracy.

All tests were performed using the `php:8.1-cli` Docker image to ensure a consistent and isolated environment. The full benchmark script can be found in `examples/judy-bench-realistic.php`.

## Benchmark Results

The following tables summarize the results for datasets ranging from 100,000 to 10,000,000 elements.

---

### **Scenario 1: Sparse Integer Keys**

| Elements     | Subject   | Write Time | Read Time | Memory Footprint  |
|--------------|-----------|------------|-----------|-------------------|
| **100k**     | PHP Array | 0.0048s    | 0.0049s   | 6.00 mb           |
|              | Judy      | 0.0854s    | 0.0741s   | **2.13 mb**       |
| **500k**     | PHP Array | 0.1012s    | 0.0401s   | 20.00 mb          |
|              | Judy      | 0.5362s    | 0.1536s   | **5.00 mb**       |
| **1M**       | Judy      | 0.2154s    | 0.2374s   | **14.00 MB**      |
|              | PHP Array | 0.1111s    | 0.0913s   | 40.00 MB          |
| **10M**      | Judy      | 4.3964s    | 7.5833s   | **142.38 MB**     |
|              | PHP Array | 6.7544s    | 1.6017s   | 434.01 MB         |

---

### **Scenario 2: Random String Keys**

| Elements     | Subject   | Write Time | Read Time | Memory Footprint  |
|--------------|-----------|------------|-----------|-------------------|
| **100k**     | PHP Array | 0.0067s    | 0.0039s   | 4.00 MB           |
|              | Judy      | 0.0183s    | 0.0159s   | **2.88 MB**       |
| **500k**     | PHP Array | 0.0561s    | 0.0391s   | 20.00 MB          |
|              | Judy      | 0.1830s    | 0.1995s   | **18.38 MB**      |
| **1M**       | PHP Array | 0.1161s    | 0.1014s   | 40.00 MB          |
|              | Judy      | 0.4350s    | 0.4270s   | **29.50 MB**      |
| **10M**      | PHP Array | 11.7977s   | 3.2145s   | 434.00 MB         |
|              | Judy      | 7.2322s    | 17.2336s  | **347.42 MB**     |

---

## Recommendations

Based on these results, the guidance is clear:

1.  **Use `Judy` for Large-Scale, Sparse Integer Data:** If an application's primary bottleneck is **memory consumption**, and it uses **large arrays of non-sequential (sparse) integers**, the Judy extension offers significant advantages. The extension's primary benefit is its memory efficiency, offering over 3x savings at scale. This can be critical in preventing memory exhaustion and may help reduce server costs.

2.  **Use Native PHP Arrays for Most Other Use Cases:** For most other scenarios, especially those involving **string-based keys** or performance-critical workloads where memory is less of a concern, native PHP arrays are often the more performant choice. PHP's native hash table is highly optimized for these workloads and delivers excellent performance.
