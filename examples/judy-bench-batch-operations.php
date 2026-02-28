<?php
/**
 * Benchmark: Judy Batch Operations vs Individual Operations vs PHP Arrays
 *
 * For each Judy type (INT_TO_INT, STRING_TO_INT) at multiple sizes, compares:
 *
 *   1. Bulk Add:    putAll() vs fromArray() vs individual $j[$k]=$v vs PHP array
 *   2. Bulk Get:    getAll() vs individual $j[$k] vs PHP array
 *   3. Conversion:  toArray() vs manual foreach vs PHP array_values
 *   4. Increment:   increment() vs manual $j[$k]+1 vs PHP $a[$k]++
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

function make_int_data(int $size): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data[$i * 3] = $i * 7;
    }
    return $data;
}

function make_string_data(int $size): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data["key_" . str_pad($i, 6, '0', STR_PAD_LEFT)] = $i;
    }
    return $data;
}

/**
 * Run the full benchmark suite for a given Judy type.
 */
function bench_type(string $type_name, int $judy_type, array $data, int $size, int $iterations): void {
    $keys = array_keys($data);

    // Keys for getAll: 10% of total + 100 guaranteed-missing keys
    $get_count = max(1000, (int)($size * 0.1));
    $get_keys = array_slice($keys, 0, $get_count);
    if ($judy_type === Judy::INT_TO_INT) {
        for ($i = 0; $i < 100; $i++) $get_keys[] = $size * 10 + $i;
    } else {
        for ($i = 0; $i < 100; $i++) $get_keys[] = "missing_$i";
    }

    // Number of unique keys for increment (1K keys, each hit many times)
    $inc_unique = min(1000, $size);
    if ($judy_type === Judy::STRING_TO_INT) {
        $inc_keys = [];
        for ($i = 0; $i < $inc_unique; $i++) $inc_keys[] = "counter_$i";
    }

    echo "===========================================================\n";
    echo "  $type_name — " . number_format($size) . " elements\n";
    echo "===========================================================\n\n";

    // ================================================================
    // 1. BULK ADD
    // ================================================================
    echo "  [1. Bulk Add: populate $size elements]\n";

    $t_php = bench_print("PHP array (foreach assign)", function() use ($data) {
        $a = [];
        foreach ($data as $k => $v) { $a[$k] = $v; }
    }, $iterations);

    $t_individual = bench_print("Judy individual \$j[\$k] = \$v", function() use ($judy_type, $data) {
        $j = new Judy($judy_type);
        foreach ($data as $k => $v) { $j[$k] = $v; }
    }, $iterations);

    $t_putall = bench_print("Judy putAll()", function() use ($judy_type, $data) {
        $j = new Judy($judy_type);
        $j->putAll($data);
    }, $iterations);

    $t_fromarray = bench_print("Judy::fromArray()", function() use ($judy_type, $data) {
        Judy::fromArray($judy_type, $data);
    }, $iterations);

    ratio("putAll() vs individual Judy", $t_individual, $t_putall);
    ratio("fromArray() vs individual Judy", $t_individual, $t_fromarray);
    ratio("Judy putAll() vs PHP array", $t_putall, $t_php);
    ratio("Judy fromArray() vs PHP array", $t_fromarray, $t_php);
    echo "\n";

    // ================================================================
    // 2. BULK GET
    // ================================================================
    $get_n = count($get_keys);
    echo "  [2. Bulk Get: fetch $get_n keys (incl. 100 missing)]\n";

    $j_read = Judy::fromArray($judy_type, $data);

    $t_php_get = bench_print("PHP array (\$a[\$k] ?? null)", function() use ($data, $get_keys) {
        $results = [];
        foreach ($get_keys as $k) { $results[$k] = $data[$k] ?? null; }
    }, $iterations);

    $t_individual_get = bench_print("Judy individual \$j[\$k]", function() use ($j_read, $get_keys) {
        $results = [];
        foreach ($get_keys as $k) { $results[$k] = $j_read[$k] ?? null; }
    }, $iterations);

    $t_getall = bench_print("Judy getAll()", function() use ($j_read, $get_keys) {
        $j_read->getAll($get_keys);
    }, $iterations);

    ratio("getAll() vs individual Judy", $t_individual_get, $t_getall);
    ratio("Judy getAll() vs PHP array", $t_getall, $t_php_get);
    echo "\n";

    // ================================================================
    // 3. CONVERSION (toArray)
    // ================================================================
    echo "  [3. Conversion: Judy to PHP array]\n";

    $t_toarray = bench_print("Judy toArray()", function() use ($j_read) {
        $j_read->toArray();
    }, $iterations);

    $t_manual = bench_print("Judy manual foreach loop", function() use ($j_read) {
        $arr = [];
        foreach ($j_read as $k => $v) { $arr[$k] = $v; }
    }, $iterations);

    ratio("toArray() vs manual foreach", $t_manual, $t_toarray);
    echo "\n";

    // ================================================================
    // 4. INCREMENT (INT_TO_INT and STRING_TO_INT only)
    // ================================================================
    echo "  [4. Increment: $size ops on $inc_unique unique keys]\n";

    if ($judy_type === Judy::INT_TO_INT) {
        $t_php_inc = bench_print("PHP array \$a[\$k]++", function() use ($size, $inc_unique) {
            $a = [];
            for ($i = 0; $i < $size; $i++) {
                $k = $i % $inc_unique;
                if (!isset($a[$k])) $a[$k] = 0;
                $a[$k]++;
            }
        }, $iterations);

        $t_judy_manual = bench_print("Judy \$j[\$k] = \$j[\$k] + 1", function() use ($size, $inc_unique) {
            $j = new Judy(Judy::INT_TO_INT);
            for ($i = 0; $i < $size; $i++) {
                $k = $i % $inc_unique;
                $j[$k] = ($j[$k] ?? 0) + 1;
            }
        }, $iterations);

        $t_judy_inc = bench_print("Judy increment()", function() use ($size, $inc_unique) {
            $j = new Judy(Judy::INT_TO_INT);
            for ($i = 0; $i < $size; $i++) {
                $j->increment($i % $inc_unique);
            }
        }, $iterations);
    } else {
        $t_php_inc = bench_print("PHP array \$a[\$k]++", function() use ($size, $inc_keys, $inc_unique) {
            $a = [];
            for ($i = 0; $i < $size; $i++) {
                $k = $inc_keys[$i % $inc_unique];
                if (!isset($a[$k])) $a[$k] = 0;
                $a[$k]++;
            }
        }, $iterations);

        $t_judy_manual = bench_print("Judy \$j[\$k] = \$j[\$k] + 1", function() use ($size, $inc_keys, $inc_unique) {
            $j = new Judy(Judy::STRING_TO_INT);
            for ($i = 0; $i < $size; $i++) {
                $k = $inc_keys[$i % $inc_unique];
                $j[$k] = ($j[$k] ?? 0) + 1;
            }
        }, $iterations);

        $t_judy_inc = bench_print("Judy increment()", function() use ($size, $inc_keys, $inc_unique) {
            $j = new Judy(Judy::STRING_TO_INT);
            for ($i = 0; $i < $size; $i++) {
                $j->increment($inc_keys[$i % $inc_unique]);
            }
        }, $iterations);
    }

    ratio("increment() vs manual Judy", $t_judy_manual, $t_judy_inc);
    ratio("Judy increment() vs PHP array", $t_judy_inc, $t_php_inc);
    echo "\n";

    unset($j_read);
}

// ---- Main ----

$sizes = [10000, 100000, 500000];
$iterations = 5;

echo "=============================================================\n";
echo "  Judy Batch Operations & Increment Benchmark\n";
echo "=============================================================\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . "\n";
echo "  Iterations: $iterations (median of each)\n";
echo "=============================================================\n\n";

foreach ($sizes as $size) {
    bench_type("INT_TO_INT", Judy::INT_TO_INT, make_int_data($size), $size, $iterations);
    bench_type("STRING_TO_INT", Judy::STRING_TO_INT, make_string_data($size), $size, $iterations);
}

echo "=============================================================\n";
echo "  Benchmark complete.\n";
echo "=============================================================\n";
