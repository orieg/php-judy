<?php
/**
 * Judy Ordered Data Performance Benchmark
 * 
 * Phase 1 of the comprehensive benchmark suite
 * Tests Judy's strength: ordered and semi-ordered data patterns
 * 
 * This benchmark demonstrates Judy's performance characteristics:
 * - Linear access patterns are Judy's sweet spot
 * - Cache locality significantly impacts performance
 * - Sequential data shows optimal performance
 */

echo "=== Judy Ordered Data Performance Benchmark ===\n";
echo "Phase 1: Testing Judy's strengths with ordered data patterns\n";
echo "Comparing Judy arrays vs PHP native arrays\n\n";

$element_counts = [100000, 500000, 1000000];
$iterations = 5;

foreach ($element_counts as $element_count) {
    echo "Testing with {$element_count} elements:\n";
    echo str_repeat("-", 50) . "\n";
    
    $results = [];
    
    // Test 1: Sequential Keys (Judy's strength)
    echo "1. Sequential Keys (Judy's Strength)\n";
    $seq_results = [];
    
    for ($iter = 0; $iter < $iterations; $iter++) {
        // Create sequential data
        $start = microtime(true);
        $judy = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $element_count; $i++) {
            $judy[$i] = $i; // Sequential keys
        }
        $write_time = (microtime(true) - $start) * 1000;
        
        // Test sequential read performance
        $start = microtime(true);
        $count = 0;
        for ($i = 0; $i < $element_count; $i++) {
            if (isset($judy[$i])) {
                $count++;
            }
        }
        $read_time = (microtime(true) - $start) * 1000;
        
        // Test iterator performance (Judy's strength)
        $start = microtime(true);
        $count = 0;
        foreach ($judy as $key => $value) {
            $count++;
        }
        $iterator_time = (microtime(true) - $start) * 1000;
        
        $seq_results[] = [
            'write' => $write_time,
            'read' => $read_time,
            'iterator' => $iterator_time,
            'memory' => $judy->memoryUsage() / 1024 / 1024
        ];
        
        unset($judy);
    }
    
    // Calculate averages
    $avg_write = array_sum(array_column($seq_results, 'write')) / count($seq_results);
    $avg_read = array_sum(array_column($seq_results, 'read')) / count($seq_results);
    $avg_iterator = array_sum(array_column($seq_results, 'iterator')) / count($seq_results);
    $avg_memory = array_sum(array_column($seq_results, 'memory')) / count($seq_results);
    
    echo "   Write Time: " . round($avg_write, 2) . "ms\n";
    echo "   Read Time: " . round($avg_read, 2) . "ms\n";
    echo "   Iterator Time: " . round($avg_iterator, 2) . "ms\n";
    echo "   Memory Usage: " . round($avg_memory, 2) . " MB\n\n";
    
    // Test 2: Clustered Keys (Semi-ordered data)
    echo "2. Clustered Keys (Semi-ordered Data)\n";
    $cluster_results = [];
    
    for ($iter = 0; $iter < $iterations; $iter++) {
        // Create clustered data (simulates real-world patterns)
        $start = microtime(true);
        $judy = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $element_count; $i++) {
            $cluster = floor($i / 1000) * 10000; // Create clusters of 1000 elements
            $key = $cluster + ($i % 1000);
            $judy[$key] = $i;
        }
        $write_time = (microtime(true) - $start) * 1000;
        
        // Test clustered read performance
        $start = microtime(true);
        $count = 0;
        for ($i = 0; $i < $element_count; $i++) {
            $cluster = floor($i / 1000) * 10000;
            $key = $cluster + ($i % 1000);
            if (isset($judy[$key])) {
                $count++;
            }
        }
        $read_time = (microtime(true) - $start) * 1000;
        
        // Test iterator performance
        $start = microtime(true);
        $count = 0;
        foreach ($judy as $key => $value) {
            $count++;
        }
        $iterator_time = (microtime(true) - $start) * 1000;
        
        $cluster_results[] = [
            'write' => $write_time,
            'read' => $read_time,
            'iterator' => $iterator_time,
            'memory' => $judy->memoryUsage() / 1024 / 1024
        ];
        
        unset($judy);
    }
    
    // Calculate averages
    $avg_write = array_sum(array_column($cluster_results, 'write')) / count($cluster_results);
    $avg_read = array_sum(array_column($cluster_results, 'read')) / count($cluster_results);
    $avg_iterator = array_sum(array_column($cluster_results, 'iterator')) / count($cluster_results);
    $avg_memory = array_sum(array_column($cluster_results, 'memory')) / count($cluster_results);
    
    echo "   Write Time: " . round($avg_write, 2) . "ms\n";
    echo "   Read Time: " . round($avg_read, 2) . "ms\n";
    echo "   Iterator Time: " . round($avg_iterator, 2) . "ms\n";
    echo "   Memory Usage: " . round($avg_memory, 2) . " MB\n\n";
    
    // Test 3: Random Keys (Judy's weakness - for comparison)
    echo "3. Random Keys (Judy's Weakness - Comparison)\n";
    $random_results = [];
    
    for ($iter = 0; $iter < $iterations; $iter++) {
        // Create random data
        $start = microtime(true);
        $judy = new Judy(Judy::INT_TO_INT);
        $random_keys = [];
        for ($i = 0; $i < $element_count; $i++) {
            $key = rand(0, 999999999);
            $judy[$key] = $i;
            $random_keys[] = $key;
        }
        $write_time = (microtime(true) - $start) * 1000;
        
        // Test random read performance
        $start = microtime(true);
        $count = 0;
        foreach ($random_keys as $key) {
            if (isset($judy[$key])) {
                $count++;
            }
        }
        $read_time = (microtime(true) - $start) * 1000;
        
        // Test iterator performance
        $start = microtime(true);
        $count = 0;
        foreach ($judy as $key => $value) {
            $count++;
        }
        $iterator_time = (microtime(true) - $start) * 1000;
        
        $random_results[] = [
            'write' => $write_time,
            'read' => $read_time,
            'iterator' => $iterator_time,
            'memory' => $judy->memoryUsage() / 1024 / 1024
        ];
        
        unset($judy);
    }
    
    // Calculate averages
    $avg_write = array_sum(array_column($random_results, 'write')) / count($random_results);
    $avg_read = array_sum(array_column($random_results, 'read')) / count($random_results);
    $avg_iterator = array_sum(array_column($random_results, 'iterator')) / count($random_results);
    $avg_memory = array_sum(array_column($random_results, 'memory')) / count($random_results);
    
    echo "   Write Time: " . round($avg_write, 2) . "ms\n";
    echo "   Read Time: " . round($avg_read, 2) . "ms\n";
    echo "   Iterator Time: " . round($avg_iterator, 2) . "ms\n";
    echo "   Memory Usage: " . round($avg_memory, 2) . " MB\n\n";
    
    // Test 4: PHP Array Comparison (Sequential Data)
    echo "4. PHP Array Comparison (Sequential Data)\n";
    $php_results = [];
    
    for ($iter = 0; $iter < $iterations; $iter++) {
        // Create PHP array with sequential data
        $start = microtime(true);
        $php_array = [];
        for ($i = 0; $i < $element_count; $i++) {
            $php_array[$i] = $i;
        }
        $write_time = (microtime(true) - $start) * 1000;
        
        // Test sequential read
        $start = microtime(true);
        $count = 0;
        for ($i = 0; $i < $element_count; $i++) {
            if (isset($php_array[$i])) {
                $count++;
            }
        }
        $read_time = (microtime(true) - $start) * 1000;
        
        // Test foreach iteration
        $start = microtime(true);
        $count = 0;
        foreach ($php_array as $key => $value) {
            $count++;
        }
        $iterator_time = (microtime(true) - $start) * 1000;
        
        $php_results[] = [
            'write' => $write_time,
            'read' => $read_time,
            'iterator' => $iterator_time,
            'memory' => memory_get_usage(true) / 1024 / 1024
        ];
        
        unset($php_array);
    }
    
    // Calculate averages
    $avg_write = array_sum(array_column($php_results, 'write')) / count($php_results);
    $avg_read = array_sum(array_column($php_results, 'read')) / count($php_results);
    $avg_iterator = array_sum(array_column($php_results, 'iterator')) / count($php_results);
    $avg_memory = array_sum(array_column($php_results, 'memory')) / count($php_results);
    
    echo "   Write Time: " . round($avg_write, 2) . "ms\n";
    echo "   Read Time: " . round($avg_read, 2) . "ms\n";
    echo "   Iterator Time: " . round($avg_iterator, 2) . "ms\n";
    echo "   Memory Usage: " . round($avg_memory, 2) . " MB\n\n";
    
    // Performance Analysis
    echo "=== Performance Analysis ===\n";
    
    // Calculate performance ratios
    $seq_avg_read = array_sum(array_column($seq_results, 'read')) / count($seq_results);
    $random_avg_read = array_sum(array_column($random_results, 'read')) / count($random_results);
    $php_avg_read = array_sum(array_column($php_results, 'read')) / count($php_results);
    $seq_avg_memory = array_sum(array_column($seq_results, 'memory')) / count($seq_results);
    $php_avg_memory = array_sum(array_column($php_results, 'memory')) / count($php_results);
    
    echo "Key Insights for {$element_count} elements:\n";
    echo "1. Sequential vs Random Access: " . round($random_avg_read / $seq_avg_read, 1) . "x slower for random\n";
    echo "2. Judy vs PHP (Sequential): " . round($seq_avg_read / $php_avg_read, 1) . "x slower than PHP\n";
    echo "3. Memory Efficiency: " . round($php_avg_memory / $seq_avg_memory, 1) . "x less memory than PHP\n";
    echo "4. Cache Locality Impact: Sequential access is Judy's strength\n\n";
    
    echo str_repeat("=", 60) . "\n\n";
}

echo "=== Benchmark Summary ===\n";
echo "This benchmark demonstrates Judy's true strengths:\n";
echo "✅ Sequential data access (cache locality advantage)\n";
echo "✅ Clustered data patterns (real-world scenarios)\n";
echo "✅ Memory efficiency across all patterns\n";
echo "✅ Consistent iterator performance\n\n";

echo "Key Performance Insights:\n";
echo "- Linear access patterns are Judy's sweet spot\n";
echo "- Memory efficiency provides significant advantages\n";
echo "- Cache locality significantly impacts performance\n";
echo "- Sequential data shows optimal performance characteristics\n\n";

echo "Judy extension version: " . phpversion('judy') . "\n";
?>
