# Judy Benchmark Improvement Plan

## Overview

Based on libjudy research and analysis, our current benchmarks don't fully demonstrate Judy's true strengths. This plan outlines how to create more comprehensive benchmarks that align with Judy's actual value propositions and performance characteristics.

## Current Benchmark Limitations

### What We're Missing
1. **Ordered/Semi-ordered data scenarios** - Judy's strongest use case
2. **Range queries and ordered iteration** - Judy's inherent advantage
3. **Cache locality demonstrations** - Judy's radix tree benefits
4. **Worst-case scenario comparisons** - Judy's predictable performance
5. **Memory efficiency at scale** - Judy's logarithmic scaling
6. **Semi-ordered or clustered data** - Real-world data patterns

### Current Benchmark Issues
- Focuses on random access (Judy's weakness)
- Uses extremely sparse keys (artificially degrades performance)
- Doesn't test Judy's strengths (ordered access, range queries)
- Missing real-world data patterns
- No cache locality analysis

## Proposed Benchmark Improvements

### 1. Ordered Data Performance Tests

#### 1.1 Sequential Key Performance
```php
// Test Judy's strength: sequential keys
$judy = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < $count; $i++) {
    $judy[$i] = $i; // Sequential keys
}

// Measure:
// - Write performance
// - Read performance (both random and sequential)
// - Iterator performance
// - Memory usage
```

**Expected Results**: Judy should significantly outperform PHP arrays for sequential data due to cache locality.

#### 1.2 Semi-ordered Data (Clustered Keys)
```php
// Test clustered data patterns
$judy = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < $count; $i++) {
    $cluster = floor($i / 1000) * 10000; // Create clusters
    $key = $cluster + ($i % 1000);
    $judy[$key] = $i;
}
```

**Expected Results**: Judy should perform well on clustered data due to locality.

### 2. Range Query Performance

#### 2.1 Range Iteration
```php
// Test range-based operations
$start = microtime(true);
$count = 0;
for ($i = 1000; $i < 2000; $i++) {
    if (isset($judy[$i])) {
        $count++;
    }
}
$range_time = microtime(true) - $start;
```

#### 2.2 Ordered Iteration
```php
// Test ordered iteration (Judy's strength)
$start = microtime(true);
$count = 0;
foreach ($judy as $key => $value) {
    $count++;
}
$ordered_time = microtime(true) - $start;
```

**Expected Results**: Judy should excel at range queries and ordered iteration.

### 3. Cache Locality Analysis

#### 3.1 Different Key Patterns
```php
// Test various key patterns
$patterns = [
    'sequential' => function($i) { return $i; },
    'sparse_small' => function($i) { return $i * 2; },
    'sparse_medium' => function($i) { return $i * 10; },
    'sparse_large' => function($i) { return $i * 100; },
    'clustered' => function($i) { return floor($i / 1000) * 10000 + ($i % 1000); },
    'random' => function($i) { return rand(0, 999999999); }
];
```

#### 3.2 Cache Miss Analysis
```php
// Measure cache performance
$start = microtime(true);
$judy->rewind();
while ($judy->valid()) {
    $key = $judy->key();
    $value = $judy->current();
    $judy->next();
}
$cache_friendly_time = microtime(true) - $start;
```

### 4. Worst-Case Scenario Testing

#### 4.1 Hash Table Degradation
```php
// Create worst-case scenario for hash tables
$php_array = [];
$collision_keys = [];
for ($i = 0; $i < $count; $i++) {
    $key = $i * 1000000; // Keys that might hash to same bucket
    $php_array[$key] = $i;
    $collision_keys[] = $key;
}

// Measure lookup performance
$start = microtime(true);
foreach ($collision_keys as $key) {
    $value = $php_array[$key];
}
$hash_degradation_time = microtime(true) - $start;
```

#### 4.2 Judy's Predictable Performance
```php
// Same test with Judy
$judy = new Judy(Judy::INT_TO_INT);
foreach ($collision_keys as $key) {
    $judy[$key] = $key;
}

$start = microtime(true);
foreach ($collision_keys as $key) {
    $value = $judy[$key];
}
$judy_predictable_time = microtime(true) - $start;
```

**Expected Results**: Judy should maintain consistent performance regardless of key patterns.

### 5. Memory Efficiency at Scale

#### 5.1 Memory Scaling Analysis
```php
$sizes = [1000, 10000, 100000, 1000000, 10000000];
$memory_data = [];

foreach ($sizes as $size) {
    // Test with different sparseness levels
    $sparseness_levels = [1, 2, 10, 100, 1000];
    
    foreach ($sparseness_levels as $sparse) {
        $judy = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) {
            $judy[$i * $sparse] = $i;
        }
        
        $memory_data[$size][$sparse] = [
            'elements' => $judy->count(),
            'memory' => $judy->memoryUsage(),
            'memory_per_element' => $judy->memoryUsage() / $judy->count()
        ];
    }
}
```

#### 5.2 Memory vs Performance Trade-offs
```php
// Analyze memory efficiency vs performance
$tradeoffs = [];
foreach ($memory_data as $size => $sparseness_data) {
    foreach ($sparseness_data as $sparse => $data) {
        $tradeoffs[$size][$sparse] = [
            'memory_efficiency' => $data['memory_per_element'],
            'performance_impact' => measure_performance($size, $sparse),
            'recommendation' => determine_recommendation($data)
        ];
    }
}
```

### 6. Real-World Data Patterns

#### 6.1 Database Primary Keys
```php
// Simulate database primary keys (often sequential with gaps)
$judy = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < $count; $i++) {
    $key = $i * 100 + rand(1, 99); // Sequential with small gaps
    $judy[$key] = $i;
}
```

#### 6.2 Log Data Patterns
```php
// Simulate log data (timestamp-based keys)
$judy = new Judy(Judy::INT_TO_INT);
$base_time = time();
for ($i = 0; $i < $count; $i++) {
    $key = $base_time + $i; // Sequential timestamps
    $judy[$key] = "log_entry_$i";
}
```

#### 6.3 Analytics Data
```php
// Simulate analytics data (clustered by time periods)
$judy = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < $count; $i++) {
    $day = floor($i / 1000) * 86400; // Group by days
    $key = $day + ($i % 1000);
    $judy[$key] = $i;
}
```

## New Benchmark Structure

### Comprehensive Benchmark Suite

#### Phase 1: Ordered Data Performance
1. **Sequential Key Tests**
   - Write performance (sequential vs random)
   - Read performance (sequential vs random)
   - Iterator performance
   - Memory usage comparison

2. **Semi-ordered Data Tests**
   - Clustered key patterns
   - Locality-based performance
   - Cache efficiency analysis

#### Phase 2: Range Query Performance
1. **Range Iteration**
   - Range-based lookups
   - Ordered iteration
   - Performance vs PHP array alternatives

2. **Ordered Operations**
   - First/last/next/prev operations
   - Range queries
   - Sorted iteration

#### Phase 3: Worst-Case Scenarios
1. **Hash Table Degradation**
   - Collision-based performance issues
   - Judy's predictable performance
   - Security implications

2. **Memory Pressure Tests**
   - Large dataset performance
   - Memory scaling analysis
   - Garbage collection impact

#### Phase 4: Real-World Patterns
1. **Database-like Patterns**
   - Primary key simulations
   - Index-like operations
   - Query performance

2. **Analytics Patterns**
   - Time-series data
   - Clustered data
   - Aggregation operations

## Expected Results and Insights

### Judy's Strengths (Should Excel)
1. **Ordered data access**: 2-5x faster than PHP arrays
2. **Range queries**: 3-10x faster than PHP array alternatives
3. **Memory efficiency**: 2-4x less memory usage
4. **Predictable performance**: No degradation with "bad" data
5. **Cache locality**: Better performance on clustered data

### Judy's Weaknesses (Should Be Honest About)
1. **Random access**: 2-5x slower than PHP arrays
2. **Small datasets**: Overhead not justified
3. **String operations**: Higher overhead than integers

### Key Insights to Demonstrate
1. **Judy is not a general-purpose replacement** for PHP arrays
2. **Judy excels at specific use cases** (ordered data, range queries, memory efficiency)
3. **Performance depends heavily on data patterns** and access methods
4. **Memory efficiency often outweighs performance costs** at scale
5. **Judy provides predictable performance** regardless of data patterns

## Implementation Plan

### Step 1: Create New Benchmark Scripts
- `benchmark_ordered_data.php` - Sequential and semi-ordered tests
- `benchmark_range_queries.php` - Range and ordered operations
- `benchmark_worst_case.php` - Hash table vs Judy comparisons
- `benchmark_real_world.php` - Database and analytics patterns

### Step 2: Update Documentation
- Revise `BENCHMARK.md` with new comprehensive results
- Add clear use case recommendations
- Provide performance expectations for different scenarios

### Step 3: Create Decision Framework
- Interactive decision tree for choosing Judy vs PHP arrays
- Performance calculator for specific use cases
- Memory vs performance trade-off analysis

### Step 4: Validation and Testing
- Run comprehensive benchmarks
- Validate results against libjudy research
- Update recommendations based on findings

## Conclusion

This improved benchmark suite will:
1. **Demonstrate Judy's true strengths** (ordered data, range queries, memory efficiency)
2. **Provide realistic performance expectations** for different use cases
3. **Help users make informed decisions** about when to use Judy
4. **Align with libjudy's documented performance characteristics**
5. **Show Judy as a specialized tool** rather than a general-purpose replacement

The goal is to position Judy as the right tool for the right job, rather than trying to compete with PHP arrays on their home turf (random access performance).
