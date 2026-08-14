<?php
/**
 * Iterator vs Sequential Access Performance Test
 * 
 * This script demonstrates why Judy's iterator is faster than
 * sequential access with sorted keys.
 */

echo "=== Iterator vs Sequential Access Performance Test ===\n";
echo "Testing 1M sparse integer keys\n\n";

// Test configuration
$element_count = 1000000;
$iterations = 5;

// Generate sparse keys
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

// Test 1: Sequential access with sorted keys (using isset)
echo "=== Test 1: Sequential Access (Sorted Keys + isset) ===\n";
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

// Test 2: Judy iterator (direct foreach)
echo "=== Test 2: Judy Iterator (Direct foreach) ===\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    $count = 0;
    foreach ($judy as $k => $v) {
        $count++;
        if ($count >= $element_count) break;
    }
    $time = microtime(true) - $start;
    $times[] = $time;
    echo "Iteration " . ($i + 1) . ": " . number_format($time, 4) . "s (read $count elements)\n";
}
echo "Average: " . number_format(array_sum($times) / count($times), 4) . "s\n\n";

// Test 3: Manual iterator (rewind/valid/current/key/next)
echo "=== Test 3: Manual Iterator (rewind/valid/current/key/next) ===\n";
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

// Test 4: Let's also test what happens if we access keys in Judy's natural order
echo "=== Test 4: Access Keys in Judy's Natural Order ===\n";
$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    $count = 0;
    $judy->rewind();
    while ($judy->valid() && $count < $element_count) {
        $k = $judy->key();
        $v = isset($judy[$k]) ? $judy[$k] : null;  // Use isset on naturally ordered keys
        $judy->next();
        $count++;
    }
    $time = microtime(true) - $start;
    $times[] = $time;
    echo "Iteration " . ($i + 1) . ": " . number_format($time, 4) . "s (read $count elements)\n";
}
echo "Average: " . number_format(array_sum($times) / count($times), 4) . "s\n\n";

echo "=== Analysis ===\n";
echo "The key insight is that Judy's iterator is faster because:\n";
echo "1. It walks through Judy's internal tree structure directly\n";
echo "2. No need to search for keys - it follows the tree's natural order\n";
echo "3. Single tree traversal vs multiple lookups\n";
echo "4. Better cache locality within Judy's memory layout\n\n";

echo "Even accessing keys in Judy's natural order with isset() is slower\n";
echo "than using the iterator directly, because isset() still requires\n";
echo "a lookup operation for each key.\n";
