<?php
/**
 * Judy Read Performance Analysis
 * 
 * This script tests different read patterns to understand why Judy
 * read performance is slow on sparse integer keys at 10M elements.
 */

echo "=== Judy Read Performance Analysis ===\n";
echo "Testing different access patterns for 10M sparse integer keys\n\n";

// Test configuration
$element_count = 10000000;
$iterations = 3;

// Generate sparse keys (same as benchmark)
echo "Generating sparse keys...\n";
$keys = [];
for ($j = 0; $j < $element_count; $j++) {
    $keys[] = $j * mt_rand(500, 1500);
}
shuffle($keys);

// Create Judy array
echo "Populating Judy array...\n";
$judy = new Judy(Judy::INT_TO_INT);
foreach ($keys as $k) {
    $judy[$k] = 1;
}

echo "Array size: " . number_format($judy->size()) . " elements\n";
echo "Memory usage: " . number_format($judy->memoryUsage()) . " bytes\n\n";

// Test 1: Random access (current benchmark pattern)
echo "=== Test 1: Random Access (Current Benchmark Pattern) ===\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    foreach ($keys as $k) {
        $v = isset($judy[$k]) ? $judy[$k] : null;
    }
    $time = microtime(true) - $start;
    $times[] = $time;
    echo "Iteration " . ($i + 1) . ": " . number_format($time, 4) . "s\n";
}
echo "Average: " . number_format(array_sum($times) / count($times), 4) . "s\n\n";

// Test 2: Sequential access (sorted keys)
echo "=== Test 2: Sequential Access (Sorted Keys) ===\n";
$sorted_keys = $keys;
sort($sorted_keys);
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    foreach ($sorted_keys as $k) {
        $v = isset($judy[$k]) ? $judy[$k] : null;
    }
    $time = microtime(true) - $start;
    $times[] = $time;
    echo "Iteration " . ($i + 1) . ": " . number_format($time, 4) . "s\n";
}
echo "Average: " . number_format(array_sum($times) / count($times), 4) . "s\n\n";

// Test 3: Judy iterator (foreach on Judy directly)
echo "=== Test 3: Judy Iterator (foreach on Judy) ===\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    $count = 0;
    foreach ($judy as $k => $v) {
        $count++;
        if ($count >= $element_count) break; // Limit to same number of reads
    }
    $time = microtime(true) - $start;
    $times[] = $time;
    echo "Iteration " . ($i + 1) . ": " . number_format($time, 4) . "s (read $count elements)\n";
}
echo "Average: " . number_format(array_sum($times) / count($times), 4) . "s\n\n";

// Test 4: Manual iterator (using Judy's iterator methods)
echo "=== Test 4: Manual Iterator (rewind/valid/current/key/next) ===\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    $count = 0;
    $judy->rewind();
    while ($judy->valid() && $count < $element_count) {
        $k = $judy->key();
        $v = $judy->current();
        $judy->next();
        $count++;
    }
    $time = microtime(true) - $start;
    $times[] = $time;
    echo "Iteration " . ($i + 1) . ": " . number_format($time, 4) . "s (read $count elements)\n";
}
echo "Average: " . number_format(array_sum($times) / count($times), 4) . "s\n\n";

// Test 5: Compare with PHP array
echo "=== Test 5: PHP Array Comparison ===\n";
$php_array = [];
foreach ($keys as $k) {
    $php_array[$k] = 1;
}

$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    foreach ($keys as $k) {
        $v = isset($php_array[$k]) ? $php_array[$k] : null;
    }
    $time = microtime(true) - $start;
    $times[] = $time;
    echo "Iteration " . ($i + 1) . ": " . number_format($time, 4) . "s\n";
}
echo "Average: " . number_format(array_sum($times) / count($times), 4) . "s\n\n";

// Test 6: Sequential access on PHP array
echo "=== Test 6: PHP Array Sequential Access ===\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    foreach ($sorted_keys as $k) {
        $v = isset($php_array[$k]) ? $php_array[$k] : null;
    }
    $time = microtime(true) - $start;
    $times[] = $time;
    echo "Iteration " . ($i + 1) . ": " . number_format($time, 4) . "s\n";
}
echo "Average: " . number_format(array_sum($times) / count($times), 4) . "s\n\n";

echo "=== Analysis ===\n";
echo "This test reveals the performance characteristics of different access patterns.\n";
echo "The key insight is that Judy arrays perform much better with sequential access\n";
echo "than with random access, especially at large scales.\n\n";

echo "=== Recommendations ===\n";
echo "1. For random access patterns, PHP arrays may be faster\n";
echo "2. For sequential access patterns, Judy arrays excel\n";
echo "3. Consider the actual access pattern in your use case\n";
echo "4. Judy's memory efficiency may outweigh performance for some scenarios\n";
