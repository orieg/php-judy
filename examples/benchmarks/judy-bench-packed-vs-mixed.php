<?php
/**
 * Benchmark: Judy INT_TO_PACKED vs INT_TO_MIXED
 *
 * Compares the two types across:
 *   1. Write throughput
 *   2. Read throughput
 *   3. Memory usage (JudyL internal via memoryUsage())
 *   4. GC cycle collection timing
 *
 * All timings are median of N iterations using hrtime(true).
 */

ini_set("memory_limit", "512M");

function bench(callable $fn, int $iterations = 5): float {
    $fn(); // warmup
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1e6; // ms
    }
    sort($times);
    return $times[intdiv($iterations, 2)];
}

function bench_print(string $label, callable $fn, int $iterations = 5): float {
    $median = bench($fn, $iterations);
    printf("  %-50s %8.3f ms\n", $label, $median);
    return $median;
}

function ratio(string $label, float $numerator, float $denominator): void {
    printf("  %-50s %.2fx\n", $label, $numerator / max($denominator, 0.001));
}

function make_mixed_values(int $size): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        // Mix of types to exercise serialization
        switch ($i % 4) {
            case 0: $data[$i] = "string_value_$i"; break;
            case 1: $data[$i] = $i * 7; break;
            case 2: $data[$i] = [$i, $i + 1, $i + 2]; break;
            case 3: $data[$i] = ($i % 2 === 0); break;
        }
    }
    return $data;
}

function bench_size(int $size, int $iterations): void {
    $data = make_mixed_values($size);
    $keys = array_keys($data);

    // Subset of keys for read benchmark
    $read_keys = array_slice($keys, 0, min($size, 10000));

    echo "===========================================================\n";
    echo "  INT_TO_PACKED vs INT_TO_MIXED — " . number_format($size) . " elements\n";
    echo "===========================================================\n\n";

    // ================================================================
    // 1. WRITE THROUGHPUT
    // ================================================================
    echo "  [1. Write: populate $size elements]\n";

    $t_mixed = bench_print("INT_TO_MIXED individual writes", function() use ($data) {
        $j = new Judy(Judy::INT_TO_MIXED);
        foreach ($data as $k => $v) { $j[$k] = $v; }
    }, $iterations);

    $t_packed = bench_print("INT_TO_PACKED individual writes", function() use ($data) {
        $j = new Judy(Judy::INT_TO_PACKED);
        foreach ($data as $k => $v) { $j[$k] = $v; }
    }, $iterations);

    ratio("PACKED/MIXED write ratio", $t_packed, $t_mixed);

    $t_mixed_bulk = bench_print("INT_TO_MIXED fromArray()", function() use ($data) {
        Judy::fromArray(Judy::INT_TO_MIXED, $data);
    }, $iterations);

    $t_packed_bulk = bench_print("INT_TO_PACKED fromArray()", function() use ($data) {
        Judy::fromArray(Judy::INT_TO_PACKED, $data);
    }, $iterations);

    ratio("PACKED/MIXED fromArray() ratio", $t_packed_bulk, $t_mixed_bulk);
    echo "\n";

    // ================================================================
    // 2. READ THROUGHPUT
    // ================================================================
    $read_n = count($read_keys);
    echo "  [2. Read: fetch $read_n elements]\n";

    $j_mixed = Judy::fromArray(Judy::INT_TO_MIXED, $data);
    $j_packed = Judy::fromArray(Judy::INT_TO_PACKED, $data);

    $t_mixed_read = bench_print("INT_TO_MIXED individual reads", function() use ($j_mixed, $read_keys) {
        foreach ($read_keys as $k) { $v = $j_mixed[$k]; }
    }, $iterations);

    $t_packed_read = bench_print("INT_TO_PACKED individual reads", function() use ($j_packed, $read_keys) {
        foreach ($read_keys as $k) { $v = $j_packed[$k]; }
    }, $iterations);

    ratio("PACKED/MIXED read ratio", $t_packed_read, $t_mixed_read);

    $t_mixed_getall = bench_print("INT_TO_MIXED getAll()", function() use ($j_mixed, $read_keys) {
        $j_mixed->getAll($read_keys);
    }, $iterations);

    $t_packed_getall = bench_print("INT_TO_PACKED getAll()", function() use ($j_packed, $read_keys) {
        $j_packed->getAll($read_keys);
    }, $iterations);

    ratio("PACKED/MIXED getAll() ratio", $t_packed_getall, $t_mixed_getall);
    echo "\n";

    // ================================================================
    // 3. MEMORY USAGE
    // ================================================================
    echo "  [3. Memory: JudyL internal memory (memoryUsage())]\n";

    $mem_mixed = $j_mixed->memoryUsage();
    $mem_packed = $j_packed->memoryUsage();

    printf("  %-50s %s bytes\n", "INT_TO_MIXED memoryUsage()", number_format($mem_mixed));
    printf("  %-50s %s bytes\n", "INT_TO_PACKED memoryUsage()", number_format($mem_packed));
    printf("  %-50s (JudyL internal is same for both types)\n", "Note:");
    echo "\n";

    // ================================================================
    // 4. GC COLLECTION TIMING
    // ================================================================
    echo "  [4. GC: gc_collect_cycles() with populated arrays]\n";

    // Build fresh arrays and force GC
    $j_mixed2 = Judy::fromArray(Judy::INT_TO_MIXED, $data);
    gc_collect_cycles(); // clean slate

    $t_gc_mixed = bench_print("gc_collect_cycles() with INT_TO_MIXED", function() {
        gc_collect_cycles();
    }, $iterations);

    unset($j_mixed2);

    $j_packed2 = Judy::fromArray(Judy::INT_TO_PACKED, $data);
    gc_collect_cycles(); // clean slate

    $t_gc_packed = bench_print("gc_collect_cycles() with INT_TO_PACKED", function() {
        gc_collect_cycles();
    }, $iterations);

    unset($j_packed2);

    ratio("PACKED/MIXED GC ratio", $t_gc_packed, $t_gc_mixed);
    echo "\n";

    // ================================================================
    // 5. ITERATION (foreach)
    // ================================================================
    echo "  [5. Iteration: foreach over all elements]\n";

    $j_mixed3 = Judy::fromArray(Judy::INT_TO_MIXED, $data);
    $j_packed3 = Judy::fromArray(Judy::INT_TO_PACKED, $data);

    $t_iter_mixed = bench_print("INT_TO_MIXED foreach", function() use ($j_mixed3) {
        foreach ($j_mixed3 as $k => $v) {}
    }, $iterations);

    $t_iter_packed = bench_print("INT_TO_PACKED foreach", function() use ($j_packed3) {
        foreach ($j_packed3 as $k => $v) {}
    }, $iterations);

    ratio("PACKED/MIXED foreach ratio", $t_iter_packed, $t_iter_mixed);
    echo "\n";

    unset($j_mixed, $j_packed, $j_mixed3, $j_packed3);
}

// ---- Main ----

$sizes = [10000, 100000, 500000];
$iterations = 5;

echo "=============================================================\n";
echo "  Judy INT_TO_PACKED vs INT_TO_MIXED Benchmark\n";
echo "=============================================================\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . "\n";
echo "  Iterations: $iterations (median of each)\n";
echo "=============================================================\n\n";

try {
    new Judy(Judy::INT_TO_PACKED);
} catch (Exception $e) {
    echo "ERROR: INT_TO_PACKED not supported on this platform\n";
    exit(1);
}

foreach ($sizes as $size) {
    bench_size($size, $iterations);
}

echo "=============================================================\n";
echo "  Benchmark complete.\n";
echo "=============================================================\n";
