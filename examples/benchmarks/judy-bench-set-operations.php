<?php
/**
 * Benchmark: Judy BITSET Set Operations vs PHP array equivalents
 *
 * Compares union, intersect, diff, and xor performance between
 * native Judy BITSET methods and PHP array_* functions.
 */

ini_set("memory_limit", "256M");

function format_bytes($size) {
    $unit = ['b', 'kb', 'mb', 'gb'];
    $i = $size > 0 ? floor(log($size, 1024)) : 0;
    return round($size / pow(1024, $i), 2) . ' ' . $unit[$i];
}

function bench($label, callable $fn, int $iterations = 5) {
    // Warmup
    $fn();

    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1e6; // ms
    }
    sort($times);
    $median = $times[intdiv($iterations, 2)];
    printf("  %-45s %8.3f ms (median of %d)\n", $label, $median, $iterations);
    return $median;
}

/**
 * Create a Judy BITSET with $count indices starting at $offset, with $gap spacing.
 */
function make_judy_bitset(int $count, int $offset = 0, int $gap = 1): Judy {
    $j = new Judy(Judy::BITSET);
    for ($i = 0; $i < $count; $i++) {
        $j[$offset + $i * $gap] = true;
    }
    return $j;
}

/**
 * Create a PHP array simulating a bitset (keys are indices, values are true).
 */
function make_php_bitset(int $count, int $offset = 0, int $gap = 1): array {
    $a = [];
    for ($i = 0; $i < $count; $i++) {
        $a[$offset + $i * $gap] = true;
    }
    return $a;
}

$sizes = [1000, 10000, 100000, 500000];
$overlap_pct = 50; // 50% overlap between sets

echo "=============================================================\n";
echo "  Judy BITSET Set Operations Benchmark\n";
echo "=============================================================\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . "\n";
echo "  Overlap: {$overlap_pct}% between sets A and B\n";
echo "=============================================================\n\n";

foreach ($sizes as $size) {
    $overlap = (int)($size * $overlap_pct / 100);
    $only_a = $size - $overlap;

    echo "--- Size: " . number_format($size) . " indices per set ";
    echo "($only_a unique + $overlap shared) ---\n\n";

    // Build sets: A = [0..size), B = [only_a..only_a+size)
    $judy_a = make_judy_bitset($size, 0);
    $judy_b = make_judy_bitset($size, $only_a);
    $php_a = make_php_bitset($size, 0);
    $php_b = make_php_bitset($size, $only_a);

    echo "  [Union]\n";
    $t_judy = bench("Judy::union()", function() use ($judy_a, $judy_b) {
        $judy_a->union($judy_b);
    });
    $t_php = bench("array_replace() keys", function() use ($php_a, $php_b) {
        array_replace($php_a, $php_b);
    });
    printf("  Speedup: %.1fx\n\n", $t_php / max($t_judy, 0.001));

    echo "  [Intersect]\n";
    $t_judy = bench("Judy::intersect()", function() use ($judy_a, $judy_b) {
        $judy_a->intersect($judy_b);
    });
    $t_php = bench("array_intersect_key()", function() use ($php_a, $php_b) {
        array_intersect_key($php_a, $php_b);
    });
    printf("  Speedup: %.1fx\n\n", $t_php / max($t_judy, 0.001));

    echo "  [Diff]\n";
    $t_judy = bench("Judy::diff()", function() use ($judy_a, $judy_b) {
        $judy_a->diff($judy_b);
    });
    $t_php = bench("array_diff_key()", function() use ($php_a, $php_b) {
        array_diff_key($php_a, $php_b);
    });
    printf("  Speedup: %.1fx\n\n", $t_php / max($t_judy, 0.001));

    echo "  [XOR (symmetric difference)]\n";
    $t_judy = bench("Judy::xor()", function() use ($judy_a, $judy_b) {
        $judy_a->xor($judy_b);
    });
    $t_php = bench("array_diff_key() x2 + array_replace()", function() use ($php_a, $php_b) {
        array_replace(
            array_diff_key($php_a, $php_b),
            array_diff_key($php_b, $php_a)
        );
    });
    printf("  Speedup: %.1fx\n\n", $t_php / max($t_judy, 0.001));

    // Memory comparison for the union result
    $mem_before = memory_get_usage();
    $judy_result = $judy_a->union($judy_b);
    $judy_mem = $judy_result->memoryUsage();
    unset($judy_result);

    $mem_before = memory_get_usage();
    $php_result = array_replace($php_a, $php_b);
    $php_mem = memory_get_usage() - $mem_before;
    unset($php_result);

    echo "  [Memory: union result]\n";
    printf("  %-45s %s\n", "Judy memoryUsage()", format_bytes($judy_mem));
    printf("  %-45s %s\n", "PHP array memory delta", format_bytes($php_mem));
    if ($php_mem > 0 && $judy_mem > 0) {
        printf("  Ratio: PHP uses %.1fx more memory\n", $php_mem / $judy_mem);
    }

    unset($judy_a, $judy_b, $php_a, $php_b);
    echo "\n";
}

echo "=============================================================\n";
echo "  Benchmark complete.\n";
echo "=============================================================\n";
