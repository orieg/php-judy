<?php
/**
 * Benchmark for Phase 4: Tiered Storage (Inline vs Linear vs Judy)
 * 
 * This script demonstrates the performance gains for small datasets (N < 128)
 * which are now handled by Tier 0 (Inline) and Tier 1 (Linear Sorted Array).
 */

function benchmark($n, $iterations = 10000) {
    echo "--- Benchmark: N = $n ($iterations iterations) ---
";

    // PHP Array Baseline
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $a = [];
        for ($k = 0; $k < $n; $k++) {
            $a[$k] = $k * 10;
        }
        $v = $a[($n-1)]; // read
    }
    $php_time = (microtime(true) - $start) * 1000;

    // Judy Tiered (PR 60)
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($k = 0; $k < $n; $k++) {
            $j[$k] = $k * 10;
        }
        $v = $j[($n-1)]; // read
    }
    $judy_time = (microtime(true) - $start) * 1000;

    printf("  PHP Array: %8.2f ms
", $php_time);
    printf("  Judy Tier: %8.2f ms
", $judy_time);
    printf("  Ratio:     %8.2fx %s

", 
        $php_time / $judy_time, 
        ($judy_time < $php_time ? "FASTER" : "slower")
    );
}

if (!extension_loaded('judy')) {
    die("Judy extension not loaded
");
}

echo "Tiered Storage Performance Comparison (Small N)
";
echo str_repeat("=", 50) . "
";

// Tier 0: Inline Storage (N=5)
benchmark(5, 50000);

// Tier 1: Linear Sorted Array (N=50)
benchmark(50, 10000);

// Tier 2: Full Judy Tree (N=200)
benchmark(200, 5000);
