<?php
// Part 3: Controller and Reporter

function convert_memory($size_in_bytes) {
    if ($size_in_bytes <= 0) return "0 b";
    $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
    $val = @round($size_in_bytes / pow(1024, ($i = floor(log($size_in_bytes, 1024)))), 2);
    return $val . ' ' . $unit[$i];
}

echo "Running PHP Array benchmarks...\n";
$php_array_results_raw = shell_exec('php -d memory_limit=4G ' . __DIR__ . '/judy-bench-realistic-php-array.php');
$php_array_results = unserialize($php_array_results_raw);
if ($php_array_results === false) {
    die("Error: Failed to unserialize PHP Array results.\n");
}

echo "Running Judy Array benchmarks...\n";
$judy_results_raw = shell_exec('php -d memory_limit=4G ' . __DIR__ . '/judy-bench-realistic-judy.php');
$judy_results = unserialize($judy_results_raw);
if ($judy_results === false) {
    die("Error: Failed to unserialize Judy Array results.\n");
}

// Merge results
$results = array_merge_recursive($php_array_results, $judy_results);
ksort($results);

// Print Results Table
echo "\n--- FINAL BENCHMARK SUMMARY ---\n\n";

foreach ($results as $scenario => $subjects) {
    echo "## $scenario\n";
    echo str_pad("Subject", 15) . str_pad("Write Time", 15) . str_pad("Read Time", 15) . "Memory Footprint\n";
    echo str_repeat('-', 60) . "\n";
    ksort($subjects); // Ensure PHP Array is first
    foreach ($subjects as $subject_name => $data) {
        $write_time = number_format($data['Write Time'], 4) . 's';
        $read_time = number_format($data['Read Time'], 4) . 's';
        $memory = convert_memory($data['Memory']);
        echo str_pad($subject_name, 15) . str_pad($write_time, 15) . str_pad($read_time, 15) . $memory . "\n";
    }
    echo "\n";
}
