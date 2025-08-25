<?php
// Part 2: Judy Array Benchmarks

function convert_memory($size_in_bytes) {
    if ($size_in_bytes <= 0) return "0 b";
    $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
    $val = @round($size_in_bytes / pow(1024, ($i = floor(log($size_in_bytes, 1024)))), 2);
    return $val . ' ' . $unit[$i];
}

function generate_random_string($length = 16) {
    return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/62))), 1, $length);
}

$results = [];
$element_counts = [100000, 500000, 1000000, 10000000];

foreach ($element_counts as $element_count) {
    // Sparse Ints
    $scenario = "Sparse Integer Keys (" . number_format($element_count) . ")";
    $keys = []; for ($i = 0; $i < $element_count; $i++) $keys[] = $i * mt_rand(500, 1500);
    shuffle($keys);

    $start_time = microtime(true);
    $judy = new Judy(Judy::INT_TO_INT); foreach ($keys as $k) $judy[$k] = 1;
    $results[$scenario]['Judy']['Write Time'] = microtime(true) - $start_time;
    // Use Judy's own memoryUsage() method for accurate memory measurement
    $results[$scenario]['Judy']['Memory'] = $judy->memoryUsage();

    $start_time = microtime(true);
    foreach ($keys as $k) { $v = isset($judy[$k]) ? $judy[$k] : null; }
    $results[$scenario]['Judy']['Read Time'] = microtime(true) - $start_time;
    unset($judy);
    unset($keys);

    // Random Strings
    $scenario = "Random String Keys (" . number_format($element_count) . ")";
    $keys = []; for ($i = 0; $i < $element_count; $i++) $keys[] = generate_random_string(16);
    shuffle($keys);

    $start_time = microtime(true);
    $judy = new Judy(Judy::STRING_TO_INT); foreach ($keys as $k) $judy[$k] = 1;
    $results[$scenario]['Judy']['Write Time'] = microtime(true) - $start_time;
    // For string-based Judy arrays, memoryUsage() returns NULL, so we use size() as approximation
    $memory_usage = $judy->memoryUsage();
    if ($memory_usage === null) {
        // Estimate memory usage based on size and average string length
        $avg_string_length = 16; // Based on generate_random_string(16)
        $results[$scenario]['Judy']['Memory'] = $judy->size() * $avg_string_length * 2; // Rough estimate
    } else {
        $results[$scenario]['Judy']['Memory'] = $memory_usage;
    }

    $start_time = microtime(true);
    foreach ($keys as $k) { $v = isset($judy[$k]) ? $judy[$k] : null; }
    $results[$scenario]['Judy']['Read Time'] = microtime(true) - $start_time;
    unset($judy);
    unset($keys);
}

echo serialize($results);
