<?php
/**
 * Benchmark: Judy Memory Usage Patterns vs PHP Arrays
 *
 * Compares memory footprint of Judy arrays (INT_TO_INT, BITSET, STRING_TO_INT)
 * against native PHP arrays at various dataset sizes.
 *
 * For JudyL (INT_TO_INT) and Judy1 (BITSET), Judy::memoryUsage() reports the
 * internal Judy memory via JudyLMemUsed / Judy1MemUsed (O(1) lookup of the
 * JPM TotalMemWords counter).
 *
 * For JudySL (STRING_TO_INT, STRING_TO_MIXED), memoryUsage() returns NULL
 * because the Judy C library provides no JSLMU macro, and JudySL allocates
 * via C malloc (invisible to PHP's memory_get_usage()).  These types are
 * included in the insertion throughput comparison but not memory footprint.
 *
 * All timings are median of N iterations using hrtime(true).
 */

ini_set("memory_limit", "1G");

// ── Helpers ──────────────────────────────────────────────────────────────

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

function fmt_bytes(int $bytes): string {
    if ($bytes >= 1024 * 1024) {
        return sprintf("%.1f MB", $bytes / (1024 * 1024));
    }
    if ($bytes >= 1024) {
        return sprintf("%.1f KB", $bytes / 1024);
    }
    return "$bytes B";
}

function ratio_str(int $a, int $b): string {
    if ($b == 0) return "n/a";
    return sprintf("%.1fx", $a / $b);
}

function run_throughput_bench(string $title, callable $php_bench, callable $judy_bench, array $sizes): void {
    echo "  [$title]\n";
    printf("  %-12s  %-14s  %-14s  %-14s\n",
        "Elements", "PHP array", "Judy", "PHP/Judy");
    printf("  %-12s  %-14s  %-14s  %-14s\n",
        str_repeat("─", 12), str_repeat("─", 14), str_repeat("─", 14), str_repeat("─", 14));

    foreach ($sizes as $size) {
        if ($size > 100000) continue; // skip 1M for throughput (too slow)
        $t_php  = $php_bench($size);
        $t_judy = $judy_bench($size);
        printf("  %-12s  %11.2f ms  %11.2f ms  %-14s\n",
            number_format($size), $t_php, $t_judy, sprintf("%.1fx", $t_judy / max($t_php, 0.001)));
    }
    echo "\n";
}

// ── Measurement helpers ─────────────────────────────────────────────────

/**
 * Measure PHP array memory consumption for integer keys.
 */
function measure_php_int_array(int $size): int {
    gc_collect_cycles();
    $before = memory_get_usage();
    $a = [];
    for ($i = 0; $i < $size; $i++) {
        $a[$i] = $i;
    }
    $after = memory_get_usage();
    unset($a);
    return $after - $before;
}

/**
 * Measure PHP array memory for bitset-like boolean storage.
 */
function measure_php_bitset_array(int $size): int {
    gc_collect_cycles();
    $before = memory_get_usage();
    $a = [];
    for ($i = 0; $i < $size; $i++) {
        $a[$i] = true;
    }
    $after = memory_get_usage();
    unset($a);
    return $after - $before;
}

/**
 * Measure Judy INT_TO_INT — uses memoryUsage() (JudyLMemUsed).
 */
function measure_judy_int_to_int(int $size): array {
    $j = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $size; $i++) {
        $j[$i] = $i;
    }
    $mem = $j->memoryUsage();
    return ['mem' => $mem, 'count' => count($j)];
}

/**
 * Measure Judy BITSET — uses memoryUsage() (Judy1MemUsed).
 */
function measure_judy_bitset(int $size): array {
    $j = new Judy(Judy::BITSET);
    for ($i = 0; $i < $size; $i++) {
        $j[$i] = true;
    }
    $mem = $j->memoryUsage();
    return ['mem' => $mem, 'count' => count($j)];
}

// ── Insertion throughput ─────────────────────────────────────────────────

function bench_insert_int_php(int $size): float {
    return bench(function() use ($size) {
        $a = [];
        for ($i = 0; $i < $size; $i++) {
            $a[$i] = $i;
        }
    });
}

function bench_insert_int_judy(int $size): float {
    return bench(function() use ($size) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) {
            $j[$i] = $i;
        }
    });
}

function bench_insert_bitset_php(int $size): float {
    return bench(function() use ($size) {
        $a = [];
        for ($i = 0; $i < $size; $i++) {
            $a[$i] = true;
        }
    });
}

function bench_insert_bitset_judy(int $size): float {
    return bench(function() use ($size) {
        $j = new Judy(Judy::BITSET);
        for ($i = 0; $i < $size; $i++) {
            $j[$i] = true;
        }
    });
}

function bench_insert_string_php(int $size): float {
    return bench(function() use ($size) {
        $a = [];
        for ($i = 0; $i < $size; $i++) {
            $a["key_$i"] = $i;
        }
    });
}

function bench_insert_string_judy(int $size): float {
    return bench(function() use ($size) {
        $j = new Judy(Judy::STRING_TO_INT);
        for ($i = 0; $i < $size; $i++) {
            $j["key_$i"] = $i;
        }
    });
}

// ── Main ─────────────────────────────────────────────────────────────────

$sizes = [1000, 10000, 100000, 1000000];

echo "=============================================================\n";
echo "  Judy Memory Usage Patterns Benchmark\n";
echo "=============================================================\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . "\n";
echo "  memory_limit = " . ini_get("memory_limit") . "\n";
echo "=============================================================\n\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 1. Memory footprint comparison
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  1. Memory Footprint Comparison\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Header
printf("  %-12s  %-14s  %-14s  %-14s  %-14s  %-14s  %-14s\n",
    "Elements", "PHP int[]", "Judy INT2INT", "Ratio", "PHP bool[]", "Judy BITSET", "Ratio");
printf("  %-12s  %-14s  %-14s  %-14s  %-14s  %-14s  %-14s\n",
    str_repeat("─", 12), str_repeat("─", 14), str_repeat("─", 14), str_repeat("─", 14),
    str_repeat("─", 14), str_repeat("─", 14), str_repeat("─", 14));

foreach ($sizes as $size) {
    $php_int     = measure_php_int_array($size);
    $judy_int    = measure_judy_int_to_int($size);
    $php_bitset  = measure_php_bitset_array($size);
    $judy_bitset = measure_judy_bitset($size);

    printf("  %-12s  %-14s  %-14s  %-14s  %-14s  %-14s  %-14s\n",
        number_format($size),
        fmt_bytes($php_int),
        fmt_bytes($judy_int['mem']),
        ratio_str($php_int, $judy_int['mem']),
        fmt_bytes($php_bitset),
        fmt_bytes($judy_bitset['mem']),
        ratio_str($php_bitset, $judy_bitset['mem']));
}
echo "\n";

echo "  Note: STRING_TO_INT / STRING_TO_MIXED memory cannot be measured\n";
echo "  directly. Judy::memoryUsage() returns NULL for these types (no\n";
echo "  JudySL memory-accounting macro in the C library), and JudySL\n";
echo "  allocates via C malloc — invisible to PHP's memory_get_usage().\n\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 2. memoryUsage() API details
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  2. Judy::memoryUsage() API Behavior by Type\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$types = [
    'BITSET'          => Judy::BITSET,
    'INT_TO_INT'      => Judy::INT_TO_INT,
    'INT_TO_MIXED'    => Judy::INT_TO_MIXED,
    'STRING_TO_INT'   => Judy::STRING_TO_INT,
    'STRING_TO_MIXED' => Judy::STRING_TO_MIXED,
];

printf("  %-20s  %-12s  %-18s  %-12s\n",
    "Type", "C Macro", "Empty Array", "1000 Elements");
printf("  %-20s  %-12s  %-18s  %-12s\n",
    str_repeat("─", 20), str_repeat("─", 12), str_repeat("─", 18), str_repeat("─", 12));

foreach ($types as $name => $type) {
    $j_empty = new Judy($type);
    $empty_val = $j_empty->memoryUsage();
    $empty_str = ($empty_val === null) ? "NULL" : fmt_bytes($empty_val);

    $j_full = new Judy($type);
    for ($i = 0; $i < 1000; $i++) {
        if ($type === Judy::STRING_TO_INT || $type === Judy::STRING_TO_MIXED) {
            $j_full["key_$i"] = ($type === Judy::STRING_TO_MIXED) ? "val_$i" : $i;
        } elseif ($type === Judy::BITSET) {
            $j_full[$i] = true;
        } else {
            $j_full[$i] = $i;
        }
    }
    $full_val = $j_full->memoryUsage();
    $full_str = ($full_val === null) ? "NULL" : fmt_bytes($full_val);

    $macro = match($type) {
        Judy::BITSET     => "J1MU",
        Judy::INT_TO_INT, Judy::INT_TO_MIXED => "JLMU",
        default          => "(none)",
    };

    printf("  %-20s  %-12s  %-18s  %-12s\n", $name, $macro, $empty_str, $full_str);
    unset($j_empty, $j_full);
}
echo "\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 3. Insertion throughput comparison
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  3. Insertion Throughput (median of 5 iterations)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

run_throughput_bench("INT_TO_INT", 'bench_insert_int_php', 'bench_insert_int_judy', $sizes);
run_throughput_bench("BITSET", 'bench_insert_bitset_php', 'bench_insert_bitset_judy', $sizes);
run_throughput_bench("STRING_TO_INT", 'bench_insert_string_php', 'bench_insert_string_judy', $sizes);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 4. Sparse key memory comparison
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  4. Sparse Key Memory (10K elements, key step = 1000)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$sparse_count = 10000;
$step = 1000;

// PHP array with sparse keys
gc_collect_cycles();
$before = memory_get_usage();
$a = [];
for ($i = 0; $i < $sparse_count; $i++) {
    $a[$i * $step] = $i;
}
$php_sparse = memory_get_usage() - $before;
unset($a);

// Judy INT_TO_INT with sparse keys
$j = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < $sparse_count; $i++) {
    $j[$i * $step] = $i;
}
$judy_sparse = $j->memoryUsage();

// Judy BITSET with sparse keys
$j2 = new Judy(Judy::BITSET);
for ($i = 0; $i < $sparse_count; $i++) {
    $j2[$i * $step] = true;
}
$judy_bitset_sparse = $j2->memoryUsage();

printf("  %-24s  %s\n", "PHP array (int keys)", fmt_bytes($php_sparse));
printf("  %-24s  %s\n", "Judy INT_TO_INT", fmt_bytes($judy_sparse));
printf("  %-24s  %s\n", "Judy BITSET", fmt_bytes($judy_bitset_sparse));
printf("  %-24s  %s\n", "PHP / Judy INT_TO_INT", ratio_str($php_sparse, $judy_sparse));
printf("  %-24s  %s\n", "PHP / Judy BITSET", ratio_str($php_sparse, $judy_bitset_sparse));
echo "\n";

unset($j, $j2);

echo "=============================================================\n";
echo "  Benchmark complete.\n";
echo "=============================================================\n";
