<?php
/**
 * Robust Benchmark System for PHP-Judy
 * 
 * This script runs multiple iterations of benchmarks and provides statistical
 * measures including min, max, median, 95th percentile, and 99th percentile.
 * 
 * Usage: php examples/run-benchmarks-robust.php [iterations] [memory_limit] [output_format]
 * Default: 20 iterations, 4G memory limit, text output
 * Output formats: text, json, both
 */

// Configuration
$iterations = isset($argv[1]) ? (int)$argv[1] : 20;
$memory_limit = isset($argv[2]) ? $argv[2] : '4G';
$output_format = isset($argv[3]) ? $argv[3] : 'text';

if (!in_array($output_format, ['text', 'json', 'both'])) {
    echo "Error: Invalid output format. Use 'text', 'json', or 'both'\n";
    exit(1);
}

echo "=== PHP-Judy Robust Benchmark System ===\n";
echo "Iterations: $iterations\n";
echo "Memory Limit: $memory_limit\n";
echo "Output Format: $output_format\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Utility functions
function convert_memory($size_in_bytes) {
    if ($size_in_bytes <= 0) return "0 b";
    $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
    $val = @round($size_in_bytes / pow(1024, ($i = floor(log($size_in_bytes, 1024)))), 2);
    return $val . ' ' . $unit[$i];
}

function calculate_statistics($values) {
    sort($values);
    $count = count($values);
    
    if ($count === 0) {
        return [
            'min' => 0,
            'max' => 0,
            'median' => 0,
            'mean' => 0,
            'p95' => 0,
            'p99' => 0,
            'stddev' => 0,
            'count' => 0
        ];
    }
    
    $min = $values[0];
    $max = $values[$count - 1];
    $mean = array_sum($values) / $count;
    
    // Median
    if ($count % 2 === 0) {
        $median = ($values[$count/2 - 1] + $values[$count/2]) / 2;
    } else {
        $median = $values[floor($count/2)];
    }
    
    // Percentiles
    $p95_index = floor($count * 0.95);
    $p99_index = floor($count * 0.99);
    $p95 = $values[$p95_index];
    $p99 = $values[$p99_index];
    
    // Standard deviation
    $variance = 0;
    foreach ($values as $value) {
        $variance += pow($value - $mean, 2);
    }
    $stddev = sqrt($variance / $count);
    
    return [
        'min' => $min,
        'max' => $max,
        'median' => $median,
        'mean' => $mean,
        'p95' => $p95,
        'p99' => $p99,
        'stddev' => $stddev,
        'count' => $count
    ];
}

function format_time($seconds) {
    if ($seconds < 0.001) {
        return sprintf('%.6fs', $seconds);
    } elseif ($seconds < 1) {
        return sprintf('%.4fs', $seconds);
    } else {
        return sprintf('%.3fs', $seconds);
    }
}

function print_statistics_table($title, $stats) {
    echo "=== $title ===\n";
    echo str_pad("Metric", 12) . str_pad("Value", 15) . "Description\n";
    echo str_repeat('-', 50) . "\n";
    echo str_pad("Min", 12) . str_pad(format_time($stats['min']), 15) . "Best performance\n";
    echo str_pad("Max", 12) . str_pad(format_time($stats['max']), 15) . "Worst performance\n";
    echo str_pad("Median", 12) . str_pad(format_time($stats['median']), 15) . "Typical performance\n";
    echo str_pad("Mean", 12) . str_pad(format_time($stats['mean']), 15) . "Average performance\n";
    echo str_pad("95th %", 12) . str_pad(format_time($stats['p95']), 15) . "95% of runs faster\n";
    echo str_pad("99th %", 12) . str_pad(format_time($stats['p99']), 15) . "99% of runs faster\n";
    echo str_pad("Std Dev", 12) . str_pad(format_time($stats['stddev']), 15) . "Variability measure\n";
    echo "\n";
}

// Benchmark scenarios
$scenarios = [
    'sparse_int_100k' => ['type' => 'sparse_int', 'count' => 100000],
    'sparse_int_500k' => ['type' => 'sparse_int', 'count' => 500000],
    'sparse_int_1m' => ['type' => 'sparse_int', 'count' => 1000000],
    'sparse_int_10m' => ['type' => 'sparse_int', 'count' => 10000000],
    'string_100k' => ['type' => 'string', 'count' => 100000],
    'string_500k' => ['type' => 'string', 'count' => 500000],
    'string_1m' => ['type' => 'string', 'count' => 1000000],
    'string_10m' => ['type' => 'string', 'count' => 10000000],
];

// Helper function to generate random strings
function generate_random_string($length = 16) {
    return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/62))), 1, $length);
}

// Results storage
$results = [];
$json_results = [
    'metadata' => [
        'iterations' => $iterations,
        'memory_limit' => $memory_limit,
        'date' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'total_scenarios' => count($scenarios),
        'total_iterations' => $iterations * count($scenarios)
    ],
    'scenarios' => []
];

echo "Running benchmarks with $iterations iterations each...\n\n";

foreach ($scenarios as $scenario_name => $scenario_config) {
    echo "Testing: $scenario_name (" . number_format($scenario_config['count']) . " elements)\n";
    
    $php_write_times = [];
    $php_read_times = [];
    $php_memory = [];
    $judy_write_times = [];
    $judy_read_times = [];
    $judy_memory = [];
    
    for ($i = 1; $i <= $iterations; $i++) {
        if ($i % 5 === 0) {
            echo "  Iteration $i/$iterations\n";
        }
        
        // Generate test data
        if ($scenario_config['type'] === 'sparse_int') {
            $keys = [];
            for ($j = 0; $j < $scenario_config['count']; $j++) {
                $keys[] = $j * mt_rand(500, 1500);
            }
            shuffle($keys);
        } else {
            $keys = [];
            for ($j = 0; $j < $scenario_config['count']; $j++) {
                $keys[] = generate_random_string(16);
            }
            shuffle($keys);
        }
        
        // Test PHP Array
        $mem_before = memory_get_usage(true);
        $start_time = microtime(true);
        $php_array = [];
        foreach ($keys as $k) {
            $php_array[$k] = 1;
        }
        $php_write_times[] = microtime(true) - $start_time;
        
        $start_time = microtime(true);
        foreach ($keys as $k) {
            $v = isset($php_array[$k]) ? $php_array[$k] : null;
        }
        $php_read_times[] = microtime(true) - $start_time;
        $php_memory[] = memory_get_usage(true) - $mem_before;
        unset($php_array);
        
        // Test Judy Array
        $mem_before = memory_get_usage(true);
        $start_time = microtime(true);
        if ($scenario_config['type'] === 'sparse_int') {
            $judy = new Judy(Judy::INT_TO_INT);
        } else {
            $judy = new Judy(Judy::STRING_TO_INT);
        }
        foreach ($keys as $k) {
            $judy[$k] = 1;
        }
        $judy_write_times[] = microtime(true) - $start_time;
        
        $start_time = microtime(true);
        foreach ($keys as $k) {
            $v = isset($judy[$k]) ? $judy[$k] : null;
        }
        $judy_read_times[] = microtime(true) - $start_time;
        
        // Memory measurement for Judy
        $memory_usage = $judy->memoryUsage();
        if ($memory_usage === null) {
            // Estimate for string-based arrays
            $avg_string_length = 16;
            $judy_memory[] = $judy->size() * $avg_string_length * 2;
        } else {
            $judy_memory[] = $memory_usage;
        }
        unset($judy);
        unset($keys);
        
        // Force garbage collection
        if ($i % 10 === 0) {
            gc_collect_cycles();
        }
    }
    
    // Calculate statistics
    $results[$scenario_name] = [
        'PHP Array' => [
            'Write Time' => calculate_statistics($php_write_times),
            'Read Time' => calculate_statistics($php_read_times),
            'Memory' => calculate_statistics($php_memory)
        ],
        'Judy' => [
            'Write Time' => calculate_statistics($judy_write_times),
            'Read Time' => calculate_statistics($judy_read_times),
            'Memory' => calculate_statistics($judy_memory)
        ]
    ];
    
    // Prepare JSON data
    $count = $scenario_config['count'];
    $type = $scenario_config['type'] === 'sparse_int' ? 'Sparse Integer Keys' : 'Random String Keys';
    
    $json_results['scenarios'][$scenario_name] = [
        'name' => $scenario_name,
        'type' => $type,
        'element_count' => $count,
        'php_array' => [
            'write_time' => $results[$scenario_name]['PHP Array']['Write Time'],
            'read_time' => $results[$scenario_name]['PHP Array']['Read Time'],
            'memory' => $results[$scenario_name]['PHP Array']['Memory']
        ],
        'judy' => [
            'write_time' => $results[$scenario_name]['Judy']['Write Time'],
            'read_time' => $results[$scenario_name]['Judy']['Read Time'],
            'memory' => $results[$scenario_name]['Judy']['Memory']
        ]
    ];
    
    echo "  Completed: $scenario_name\n\n";
}

// Save JSON results
if ($output_format === 'json' || $output_format === 'both') {
    $json_filename = 'benchmark_results_' . date('Y-m-d_H-i-s') . '.json';
    file_put_contents($json_filename, json_encode($json_results, JSON_PRETTY_PRINT));
    echo "JSON results saved to: $json_filename\n\n";
}

// Print comprehensive results (text format)
if ($output_format === 'text' || $output_format === 'both') {
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "COMPREHENSIVE BENCHMARK RESULTS\n";
    echo str_repeat('=', 80) . "\n\n";

    foreach ($results as $scenario_name => $scenario_results) {
        $count = $scenarios[$scenario_name]['count'];
        $type = $scenarios[$scenario_name]['type'] === 'sparse_int' ? 'Sparse Integer Keys' : 'Random String Keys';
        
        echo "## $type (" . number_format($count) . " elements)\n";
        echo str_repeat('-', 60) . "\n\n";
        
        foreach ($scenario_results as $subject => $metrics) {
            echo "### $subject\n";
            echo "Write Time Statistics:\n";
            print_statistics_table("Write Time", $metrics['Write Time']);
            
            echo "Read Time Statistics:\n";
            print_statistics_table("Read Time", $metrics['Read Time']);
            
            echo "Memory Usage Statistics:\n";
            $memory_stats = $metrics['Memory'];
            echo "Min: " . convert_memory($memory_stats['min']) . "\n";
            echo "Max: " . convert_memory($memory_stats['max']) . "\n";
            echo "Median: " . convert_memory($memory_stats['median']) . "\n";
            echo "Mean: " . convert_memory($memory_stats['mean']) . "\n";
            echo "Std Dev: " . convert_memory($memory_stats['stddev']) . "\n\n";
        }
        
        echo str_repeat('-', 60) . "\n\n";
    }

    // Summary comparison
    echo "## SUMMARY COMPARISON (Median Values)\n";
    echo str_repeat('=', 80) . "\n\n";

    echo str_pad("Scenario", 25) . str_pad("Subject", 12) . str_pad("Write (s)", 12) . str_pad("Read (s)", 12) . str_pad("Memory", 15) . "\n";
    echo str_repeat('-', 80) . "\n";

    foreach ($results as $scenario_name => $scenario_results) {
        $count = $scenarios[$scenario_name]['count'];
        $type = $scenarios[$scenario_name]['type'] === 'sparse_int' ? 'Sparse Int' : 'String';
        $scenario_display = $type . " " . number_format($count);
        
        foreach ($scenario_results as $subject => $metrics) {
            $write_median = format_time($metrics['Write Time']['median']);
            $read_median = format_time($metrics['Read Time']['median']);
            $memory_median = convert_memory($metrics['Memory']['median']);
            
            echo str_pad($scenario_display, 25) . 
                 str_pad($subject, 12) . 
                 str_pad($write_median, 12) . 
                 str_pad($read_median, 12) . 
                 str_pad($memory_median, 15) . "\n";
        }
    }

    echo "\n" . str_repeat('=', 80) . "\n";
    echo "Benchmark completed at: " . date('Y-m-d H:i:s') . "\n";
    echo "Total iterations: " . ($iterations * count($scenarios)) . "\n";
    if ($output_format === 'both') {
        echo "JSON results saved to: $json_filename\n";
    }
    echo str_repeat('=', 80) . "\n";
}
