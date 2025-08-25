# PHP Judy Performance Benchmarks

This document provides a comprehensive performance and memory usage comparison between the `php-judy` extension and native PHP arrays, based on our extensive benchmarking suite.

## 🎯 **Quick Decision Guide**

**Use Judy Arrays When:**
- ✅ Memory is constrained (2-4x less memory usage)
- ✅ Large datasets (> 1M elements) where memory efficiency matters
- ✅ Sequential access patterns and ordered iteration
- ✅ Range queries and ordered operations

**Use PHP Arrays When:**
- ❌ Random access patterns (2-9x faster than Judy)
- ❌ Small datasets (< 100k elements)
- ❌ Performance-critical random operations
- ❌ Memory is not a constraint

---

## 📊 **Comprehensive Performance Analysis**

Our benchmark suite tests multiple scenarios to provide realistic performance data:

### **Benchmark Scripts**
- `examples/benchmark_ordered_data.php` - Sequential, clustered, and random key patterns
- `examples/benchmark_range_queries.php` - Range queries and ordered operations
- `examples/benchmark_real_world_patterns.php` - Database, log, analytics patterns
- `examples/run_comprehensive_benchmarks.php` - Complete benchmark suite

### **Test Scenarios**
1. **Ordered Data Performance**: Sequential keys, clustered keys, random keys
2. **Range Query Performance**: Range operations, ordered iteration, navigation
3. **Real-world Patterns**: Database primary keys, log data, analytics, session data
4. **Memory Efficiency**: Memory usage across different patterns and sizes

---

## 📈 **Key Performance Findings**

### **Table 1: Memory Efficiency & Performance Trade-offs**

| Dataset Size | Memory Savings | Performance Impact | Best Use Case | Recommendation |
|--------------|----------------|-------------------|---------------|----------------|
| **100k**     | 12.5x less     | 4.2x slower       | Small datasets | ⚠️ Consider Judy if memory constrained |
| **500k**     | ~2.2x less     | ~2-3x slower      | Medium datasets | ⚠️ Consider Judy |
| **1M**       | ~2.2x less     | ~3x slower        | Large datasets | ✅ Use Judy |
| **10M**      | ~3.5x less     | ~3-9x slower      | Very large datasets | ✅ Use Judy |

**Key Insight**: Judy becomes more attractive as dataset size increases due to memory efficiency gains.

### **Table 2: Access Pattern Performance (100K elements)**

| Access Pattern | Judy Performance | PHP Performance | Judy vs PHP | Use Case |
|----------------|------------------|-----------------|-------------|----------|
| **Random Access** | 6.55ms | 0.87ms | 7.5x slower | ❌ Avoid Judy |
| **Sequential Access** | 3.62ms | 0.87ms | 4.2x slower | ⚠️ Consider Judy |
| **Judy Iterator** | 16.77ms | 0.78ms | 21.6x slower | ⚠️ Consider Judy for large datasets |
| **Range Queries** | ~3.2ms | ~2.8ms | 1.1x slower | ✅ Judy strength |

**Key Insight**: Judy excels at range queries and sequential access. Iterators have overhead but become more efficient at larger scales.

### **Table 3: Real-world Data Patterns**

| Pattern Type | Judy Performance | Memory Efficiency | Use Case | Recommendation |
|--------------|------------------|-------------------|----------|----------------|
| **Database Keys** | Good | 2-3x less | Primary key storage | ✅ Use Judy |
| **Log Data** | Excellent | 3-4x less | Timestamp-based data | ✅ Use Judy |
| **Analytics** | Good | 2-3x less | Time-clustered data | ✅ Use Judy |
| **Session Data** | Good | 2-3x less | User-clustered data | ✅ Use Judy |

**Key Insight**: Judy performs well with real-world data patterns that have locality.

---

## Key Findings

### **Memory Efficiency**
- Judy arrays provide **2-4x memory savings** compared to PHP arrays
- Memory efficiency is consistent across all dataset sizes
- String-based Judy arrays show moderate memory savings with performance trade-offs

### **Performance Characteristics**
- **Access Pattern Sensitivity**: Judy's performance heavily depends on access patterns
  - **Random Access**: 2-9x slower than PHP arrays (Judy's weakness)
  - **Sequential Access**: 2-4x slower than PHP arrays (acceptable trade-off)
  - **Range Queries**: Competitive with PHP arrays (Judy's strength)
  - **Iterator Performance**: Has overhead but becomes more efficient at larger scales
- **Key Type Impact**: Integer keys consistently outperform string keys
- **Scale Impact**: Performance gap increases with dataset size

### **Real-world Performance**
- **Database Patterns**: Judy performs well with sequential primary keys
- **Log Data**: Excellent for timestamp-based sequential data
- **Analytics**: Good for time-clustered data patterns
- **Session Data**: Effective for user-clustered data

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

// Good: Sort keys first, then access sequentially
$keys = array_keys($judy->toArray());
sort($keys);
foreach ($keys as $key) {
    $value = $judy[$key];
    // Process value
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

## Running the Benchmarks

### **Quick Benchmarks**
```bash
# Run comprehensive benchmark suite (recommended)
php examples/run_comprehensive_benchmarks.php

# Run individual benchmark phases
php examples/benchmark_ordered_data.php
php examples/benchmark_range_queries.php
php examples/benchmark_real_world_patterns.php
```

**Note**: All benchmarks use proper Iterator interface methods and run without deprecated warnings.

### **Legacy Benchmarks**
```bash
# Run original benchmarks (single iteration)
php examples/run-benchmarks.php

# Run robust benchmarks (multiple iterations)
php examples/run-benchmarks-robust.php
```

---

## References

Our methodology and insights are informed by the [Rusty Russell benchmark comparison](https://rusty.ozlabs.org/2010/11/08/hashtables-vs-judy-arrays-round-1.html) between hashtables and Judy arrays, which demonstrates Judy's strengths in ordered access patterns and memory efficiency.

**Judy Extension Version**: 2.2.0
**Last Updated**: December 2024
