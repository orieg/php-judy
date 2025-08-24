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
| **1M**       | PHP Array | 0.1193s    | 0.1155s   | 40.00 mb          |
|              | Judy      | 0.2426s    | 0.1415s   | **~10 mb***       |
| **10M**      | PHP Array | 3.3881s    | 1.0481s   | 434.00 mb         |
|              | Judy      | 3.9460s    | 2.2611s   | **142.25 mb**     |

*\* Note: The 1M element test for sparse integer keys consistently reported an anomalous memory reading of 0 bytes. The value shown is an estimate based on the clear scaling trend from the other tests.*

---

### **Scenario 2: Random String Keys**

| Elements     | Subject   | Write Time | Read Time | Memory Footprint  |
|--------------|-----------|------------|-----------|-------------------|
| **100k**     | PHP Array | 0.0048s    | 0.0042s   | 4.00 mb           |
|              | Judy      | 0.1054s    | 0.0726s   | **2.88 mb**       |
| **500k**     | PHP Array | 0.1372s    | 0.1010s   | 20.00 mb          |
|              | Judy      | 0.2543s    | 0.1097s   | **18.38 mb**      |
| **1M**       | PHP Array | 0.2753s    | 0.0962s   | 40.00 mb          |
|              | Judy      | 0.5849s    | 0.3586s   | **31.50 mb**      |
| **10M**      | PHP Array | 4.1559s    | 1.1335s   | 434.00 mb         |
|              | Judy      | 5.2070s    | 5.3208s   | **347.48 mb**     |

---

## Recommendations

Based on these results, the guidance is clear:

1.  **Use `Judy` for Large-Scale, Sparse Integer Data:** If an application's primary bottleneck is **memory consumption**, and it uses **large arrays of non-sequential (sparse) integers**, the Judy extension offers significant advantages. The extension's primary benefit is its memory efficiency, offering over 3x savings at scale. This can be critical in preventing memory exhaustion and may help reduce server costs.

2.  **Use Native PHP Arrays for Most Other Use Cases:** For most other scenarios, especially those involving **string-based keys** or performance-critical workloads where memory is less of a concern, native PHP arrays are often the more performant choice. PHP's native hash table is highly optimized for these workloads and delivers excellent performance.
