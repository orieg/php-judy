<?php
/**
 * Debug Judy Iterator Performance
 * 
 * Simple test to understand why Judy iterators appear so slow
 */

echo "=== Judy Iterator Performance Debug ===\n\n";

$element_count = 100000;
$iterations = 3;

echo "Testing with {$element_count} elements, {$iterations} iterations\n\n";

// Test 1: Judy Iterator Performance
echo "1. Judy Iterator Performance\n";
$judy_times = [];

for ($iter = 0; $iter < $iterations; $iter++) {
    // Create Judy array
    $judy = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $element_count; $i++) {
        $judy[$i] = $i;
    }
    
    // Test iterator performance
    $start = microtime(true);
    $count = 0;
    foreach ($judy as $key => $value) {
        $count++;
    }
    $iterator_time = (microtime(true) - $start) * 1000;
    
    $judy_times[] = $iterator_time;
    echo "   Iteration " . ($iter + 1) . ": " . round($iterator_time, 2) . "ms, count: {$count}\n";
    
    unset($judy);
}

$avg_judy = array_sum($judy_times) / count($judy_times);
echo "   Average Judy Iterator: " . round($avg_judy, 2) . "ms\n\n";

// Test 2: PHP Array Iterator Performance
echo "2. PHP Array Iterator Performance\n";
$php_times = [];

for ($iter = 0; $iter < $iterations; $iter++) {
    // Create PHP array
    $php_array = [];
    for ($i = 0; $i < $element_count; $i++) {
        $php_array[$i] = $i;
    }
    
    // Test iterator performance
    $start = microtime(true);
    $count = 0;
    foreach ($php_array as $key => $value) {
        $count++;
    }
    $iterator_time = (microtime(true) - $start) * 1000;
    
    $php_times[] = $iterator_time;
    echo "   Iteration " . ($iter + 1) . ": " . round($iterator_time, 2) . "ms, count: {$count}\n";
    
    unset($php_array);
}

$avg_php = array_sum($php_times) / count($php_times);
echo "   Average PHP Iterator: " . round($avg_php, 2) . "ms\n\n";

// Test 3: Judy Manual Iterator Performance
echo "3. Judy Manual Iterator Performance\n";
$manual_times = [];

for ($iter = 0; $iter < $iterations; $iter++) {
    // Create Judy array
    $judy = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $element_count; $i++) {
        $judy[$i] = $i;
    }
    
    // Test manual iterator performance
    $start = microtime(true);
    $count = 0;
    $judy->rewind();
    while ($judy->valid()) {
        $key = $judy->key();
        $value = $judy->current();
        $count++;
        $judy->next();
    }
    $iterator_time = (microtime(true) - $start) * 1000;
    
    $manual_times[] = $iterator_time;
    echo "   Iteration " . ($iter + 1) . ": " . round($iterator_time, 2) . "ms, count: {$count}\n";
    
    unset($judy);
}

$avg_manual = array_sum($manual_times) / count($manual_times);
echo "   Average Manual Iterator: " . round($avg_manual, 2) . "ms\n\n";

// Test 4: Judy Sequential Access Performance
echo "4. Judy Sequential Access Performance\n";
$seq_times = [];

for ($iter = 0; $iter < $iterations; $iter++) {
    // Create Judy array
    $judy = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $element_count; $i++) {
        $judy[$i] = $i;
    }
    
    // Test sequential access performance
    $start = microtime(true);
    $count = 0;
    for ($i = 0; $i < $element_count; $i++) {
        if (isset($judy[$i])) {
            $count++;
        }
    }
    $access_time = (microtime(true) - $start) * 1000;
    
    $seq_times[] = $access_time;
    echo "   Iteration " . ($iter + 1) . ": " . round($access_time, 2) . "ms, count: {$count}\n";
    
    unset($judy);
}

$avg_seq = array_sum($seq_times) / count($seq_times);
echo "   Average Sequential Access: " . round($avg_seq, 2) . "ms\n\n";

// Performance Comparison
echo "=== Performance Comparison ===\n";
$ratio_foreach = $avg_judy / $avg_php;
$ratio_manual = $avg_manual / $avg_php;
$ratio_seq = $avg_seq / $avg_php;

echo "Judy foreach vs PHP foreach: " . round($ratio_foreach, 1) . "x slower\n";
echo "Judy manual vs PHP foreach: " . round($ratio_manual, 1) . "x slower\n";
echo "Judy sequential vs PHP foreach: " . round($ratio_seq, 1) . "x slower\n";
echo "Judy foreach vs Judy sequential: " . round($avg_judy / $avg_seq, 1) . "x slower\n";
echo "Judy manual vs Judy sequential: " . round($avg_manual / $avg_seq, 1) . "x slower\n\n";

echo "Judy extension version: " . phpversion('judy') . "\n";
?>
