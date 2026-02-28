<?php
/**
 * Benchmark: Judy Batch Operations vs Individual Operations vs PHP Arrays
 *
 * Compares:
 * - Bulk add: putAll() vs individual $j[$k]=$v vs PHP array
 * - Bulk get: getAll() vs individual $j[$k] vs PHP array
 * - Conversion: fromArray()/toArray() vs manual loops
 *
 * Tests INT_TO_INT and STRING_TO_INT types.
 */

ini_set("memory_limit", "512M");

function format_bytes($size) {
    $unit = ['b', 'kb', 'mb', 'gb'];
    $i = $size > 0 ? floor(log($size, 1024)) : 0;
    return round($size / pow(1024, $i), 2) . ' ' . $unit[$i];
}

function bench(callable $fn, int $iterations = 5): float {
    // Warmup
    $fn();

    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1e6; // ms
    }
    sort($times);
    return $times[intdiv($iterations, 2)]; // median
}

function bench_print(string $label, callable $fn, int $iterations = 5): float {
    $median = bench($fn, $iterations);
    printf("  %-50s %8.3f ms\n", $label, $median);
    return $median;
}

/**
 * Build a PHP array of random int=>int data for a given size.
 */
function make_int_data(int $size): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data[$i * 3] = $i * 7; // sparse keys with integer values
    }
    return $data;
}

/**
 * Build a PHP array of random string=>int data for a given size.
 */
function make_string_data(int $size): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data["key_" . str_pad($i, 6, '0', STR_PAD_LEFT)] = $i;
    }
    return $data;
}

$sizes = [10000, 100000, 500000];
$iterations = 5;

echo "=============================================================\n";
echo "  Judy Batch Operations Benchmark\n";
echo "=============================================================\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . "\n";
echo "  Iterations: $iterations (median)\n";
echo "=============================================================\n\n";

foreach ($sizes as $size) {
    $int_data = make_int_data($size);
    $keys_int = array_keys($int_data);
    // Pick a subset of keys for getAll (10% of total, random sample)
    $get_count = max(1000, (int)($size * 0.1));
    $get_keys_int = array_slice($keys_int, 0, $get_count);
    // Add some missing keys
    $get_keys_int_with_missing = $get_keys_int;
    for ($i = 0; $i < 100; $i++) {
        $get_keys_int_with_missing[] = $size * 10 + $i; // guaranteed missing
    }

    echo "===========================================================\n";
    echo "  INT_TO_INT — " . number_format($size) . " elements\n";
    echo "===========================================================\n\n";

    // ---- BULK ADD ----
    echo "  [Bulk Add: populate $size elements]\n";

    $t_php = bench_print("PHP array (literal assignment)", function() use ($int_data, $size) {
        $a = [];
        foreach ($int_data as $k => $v) {
            $a[$k] = $v;
        }
    }, $iterations);

    $t_individual = bench_print("Judy individual \$j[\$k] = \$v", function() use ($int_data, $size) {
        $j = new Judy(Judy::INT_TO_INT);
        foreach ($int_data as $k => $v) {
            $j[$k] = $v;
        }
    }, $iterations);

    $t_putall = bench_print("Judy putAll()", function() use ($int_data, $size) {
        $j = new Judy(Judy::INT_TO_INT);
        $j->putAll($int_data);
    }, $iterations);

    $t_fromarray = bench_print("Judy::fromArray()", function() use ($int_data, $size) {
        $j = Judy::fromArray(Judy::INT_TO_INT, $int_data);
    }, $iterations);

    printf("  %-50s %.2fx\n", "putAll() vs individual", $t_individual / max($t_putall, 0.001));
    printf("  %-50s %.2fx\n", "fromArray() vs individual", $t_individual / max($t_fromarray, 0.001));
    printf("  %-50s %.2fx\n", "PHP array vs Judy putAll()", $t_putall / max($t_php, 0.001));
    echo "\n";

    // ---- BULK GET ----
    $get_n = count($get_keys_int_with_missing);
    echo "  [Bulk Get: fetch $get_n keys (incl. 100 missing)]\n";

    // Pre-populate for read tests
    $j_read = new Judy(Judy::INT_TO_INT);
    $j_read->putAll($int_data);

    $t_php_get = bench_print("PHP array (individual access)", function() use ($int_data, $get_keys_int_with_missing) {
        $results = [];
        foreach ($get_keys_int_with_missing as $k) {
            $results[$k] = $int_data[$k] ?? null;
        }
    }, $iterations);

    $t_individual_get = bench_print("Judy individual \$j[\$k]", function() use ($j_read, $get_keys_int_with_missing) {
        $results = [];
        foreach ($get_keys_int_with_missing as $k) {
            $results[$k] = $j_read[$k] ?? null;
        }
    }, $iterations);

    $t_getall = bench_print("Judy getAll()", function() use ($j_read, $get_keys_int_with_missing) {
        $results = $j_read->getAll($get_keys_int_with_missing);
    }, $iterations);

    printf("  %-50s %.2fx\n", "getAll() vs individual Judy", $t_individual_get / max($t_getall, 0.001));
    printf("  %-50s %.2fx\n", "PHP array vs Judy getAll()", $t_getall / max($t_php_get, 0.001));
    echo "\n";

    // ---- CONVERSION ----
    echo "  [Conversion: toArray()]\n";

    $t_toarray = bench_print("Judy toArray()", function() use ($j_read) {
        $arr = $j_read->toArray();
    }, $iterations);

    $t_manual = bench_print("Judy manual foreach loop", function() use ($j_read) {
        $arr = [];
        foreach ($j_read as $k => $v) {
            $arr[$k] = $v;
        }
    }, $iterations);

    printf("  %-50s %.2fx\n", "toArray() vs manual foreach", $t_manual / max($t_toarray, 0.001));
    echo "\n";

    unset($j_read, $int_data, $keys_int, $get_keys_int, $get_keys_int_with_missing);

    // ---- STRING_TO_INT ----
    $string_data = make_string_data($size);
    $keys_str = array_keys($string_data);
    $get_keys_str = array_slice($keys_str, 0, $get_count);
    $get_keys_str_with_missing = $get_keys_str;
    for ($i = 0; $i < 100; $i++) {
        $get_keys_str_with_missing[] = "missing_" . $i;
    }

    echo "===========================================================\n";
    echo "  STRING_TO_INT — " . number_format($size) . " elements\n";
    echo "===========================================================\n\n";

    // ---- BULK ADD ----
    echo "  [Bulk Add: populate $size elements]\n";

    $t_php = bench_print("PHP array (literal assignment)", function() use ($string_data, $size) {
        $a = [];
        foreach ($string_data as $k => $v) {
            $a[$k] = $v;
        }
    }, $iterations);

    $t_individual = bench_print("Judy individual \$j[\$k] = \$v", function() use ($string_data, $size) {
        $j = new Judy(Judy::STRING_TO_INT);
        foreach ($string_data as $k => $v) {
            $j[$k] = $v;
        }
    }, $iterations);

    $t_putall = bench_print("Judy putAll()", function() use ($string_data, $size) {
        $j = new Judy(Judy::STRING_TO_INT);
        $j->putAll($string_data);
    }, $iterations);

    $t_fromarray = bench_print("Judy::fromArray()", function() use ($string_data, $size) {
        $j = Judy::fromArray(Judy::STRING_TO_INT, $string_data);
    }, $iterations);

    printf("  %-50s %.2fx\n", "putAll() vs individual", $t_individual / max($t_putall, 0.001));
    printf("  %-50s %.2fx\n", "fromArray() vs individual", $t_individual / max($t_fromarray, 0.001));
    printf("  %-50s %.2fx\n", "PHP array vs Judy putAll()", $t_putall / max($t_php, 0.001));
    echo "\n";

    // ---- BULK GET ----
    $get_n = count($get_keys_str_with_missing);
    echo "  [Bulk Get: fetch $get_n keys (incl. 100 missing)]\n";

    $j_read = new Judy(Judy::STRING_TO_INT);
    $j_read->putAll($string_data);

    $t_php_get = bench_print("PHP array (individual access)", function() use ($string_data, $get_keys_str_with_missing) {
        $results = [];
        foreach ($get_keys_str_with_missing as $k) {
            $results[$k] = $string_data[$k] ?? null;
        }
    }, $iterations);

    $t_individual_get = bench_print("Judy individual \$j[\$k]", function() use ($j_read, $get_keys_str_with_missing) {
        $results = [];
        foreach ($get_keys_str_with_missing as $k) {
            $results[$k] = $j_read[$k] ?? null;
        }
    }, $iterations);

    $t_getall = bench_print("Judy getAll()", function() use ($j_read, $get_keys_str_with_missing) {
        $results = $j_read->getAll($get_keys_str_with_missing);
    }, $iterations);

    printf("  %-50s %.2fx\n", "getAll() vs individual Judy", $t_individual_get / max($t_getall, 0.001));
    printf("  %-50s %.2fx\n", "PHP array vs Judy getAll()", $t_getall / max($t_php_get, 0.001));
    echo "\n";

    // ---- CONVERSION ----
    echo "  [Conversion: toArray()]\n";

    $t_toarray = bench_print("Judy toArray()", function() use ($j_read) {
        $arr = $j_read->toArray();
    }, $iterations);

    $t_manual = bench_print("Judy manual foreach loop", function() use ($j_read) {
        $arr = [];
        foreach ($j_read as $k => $v) {
            $arr[$k] = $v;
        }
    }, $iterations);

    printf("  %-50s %.2fx\n", "toArray() vs manual foreach", $t_manual / max($t_toarray, 0.001));
    echo "\n";

    unset($j_read, $string_data, $keys_str, $get_keys_str, $get_keys_str_with_missing);
}

// ---- INCREMENT BENCHMARK ----
echo "===========================================================\n";
echo "  Increment Benchmark\n";
echo "===========================================================\n\n";

foreach ($sizes as $size) {
    echo "  --- " . number_format($size) . " increments ---\n";

    // INT_TO_INT increment
    echo "  [INT_TO_INT]\n";

    $t_php_inc = bench_print("PHP array \$a[\$k]++", function() use ($size) {
        $a = [];
        for ($i = 0; $i < $size; $i++) {
            $k = $i % 1000; // 1000 unique keys, each incremented many times
            if (!isset($a[$k])) $a[$k] = 0;
            $a[$k]++;
        }
    }, $iterations);

    $t_judy_manual = bench_print("Judy \$j[\$k] = \$j[\$k] + 1", function() use ($size) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) {
            $k = $i % 1000;
            $j[$k] = ($j[$k] ?? 0) + 1;
        }
    }, $iterations);

    $t_judy_inc = bench_print("Judy increment()", function() use ($size) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) {
            $j->increment($i % 1000);
        }
    }, $iterations);

    printf("  %-50s %.2fx\n", "increment() vs manual Judy", $t_judy_manual / max($t_judy_inc, 0.001));
    printf("  %-50s %.2fx\n", "PHP array vs Judy increment()", $t_judy_inc / max($t_php_inc, 0.001));
    echo "\n";

    // STRING_TO_INT increment
    echo "  [STRING_TO_INT]\n";

    // Pre-generate keys to avoid measuring string creation overhead
    $str_keys = [];
    for ($i = 0; $i < 1000; $i++) {
        $str_keys[] = "counter_" . $i;
    }

    $t_php_inc = bench_print("PHP array \$a[\$k]++", function() use ($size, $str_keys) {
        $a = [];
        for ($i = 0; $i < $size; $i++) {
            $k = $str_keys[$i % 1000];
            if (!isset($a[$k])) $a[$k] = 0;
            $a[$k]++;
        }
    }, $iterations);

    $t_judy_manual = bench_print("Judy \$j[\$k] = \$j[\$k] + 1", function() use ($size, $str_keys) {
        $j = new Judy(Judy::STRING_TO_INT);
        for ($i = 0; $i < $size; $i++) {
            $k = $str_keys[$i % 1000];
            $j[$k] = ($j[$k] ?? 0) + 1;
        }
    }, $iterations);

    $t_judy_inc = bench_print("Judy increment()", function() use ($size, $str_keys) {
        $j = new Judy(Judy::STRING_TO_INT);
        for ($i = 0; $i < $size; $i++) {
            $j->increment($str_keys[$i % 1000]);
        }
    }, $iterations);

    printf("  %-50s %.2fx\n", "increment() vs manual Judy", $t_judy_manual / max($t_judy_inc, 0.001));
    printf("  %-50s %.2fx\n", "PHP array vs Judy increment()", $t_judy_inc / max($t_php_inc, 0.001));
    echo "\n";
}

echo "=============================================================\n";
echo "  Benchmark complete.\n";
echo "=============================================================\n";
