<?php
/**
 * Benchmark: Phase 4 — Tiered Storage & Vtable Dispatch
 *
 * Compares three subjects in the SAME process (no cross-run variance):
 *   1. PHP native array       — baseline
 *   2. Judy (direct-to-tree)  — judy.tiered_storage=0
 *   3. Judy (tiered)          — judy.tiered_storage=1
 *
 * Focuses on sizes where Judy is actually used: 1K–500K elements,
 * plus a few medium sizes (100, 500) to show tier transition behaviour.
 *
 * Usage:
 *   php examples/judy-bench-tiered-storage.php [iterations]
 *   php examples/judy-bench-tiered-storage.php 5
 */

ini_set('memory_limit', '2G');

if (!extension_loaded('judy')) {
    die("Judy extension not loaded\n");
}

$iterations = isset($argv[1]) ? (int)$argv[1] : 5;

// ── Helpers ──────────────────────────────────────────────────────────────

function bench_median(callable $fn, int $iterations): float {
    $fn(); // warmup
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start  = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);
    return $times[intdiv($iterations, 2)];
}

function fmt(float $ms): string { return sprintf('%10.2f ms', $ms); }
function fmt_mem(int $bytes): string {
    if ($bytes >= 1048576) return sprintf('%8.1f MB', $bytes / 1048576);
    if ($bytes >= 1024)    return sprintf('%8.1f KB', $bytes / 1024);
    return sprintf('%8d  B', $bytes);
}
function ratio_str(float $baseline, float $subject): string {
    if ($subject <= 0) return '       n/a';
    $r = $baseline / $subject;
    if ($r >= 1.0) return sprintf('%7.1fx', $r);
    return sprintf('%7.2fx', $r);
}
function change_str(float $before, float $after): string {
    if ($before <= 0) return '     n/a';
    $pct = ($after - $before) / $before * 100;
    if (abs($pct) < 1) return '      ~0%';
    return sprintf('%+6.0f%%', $pct);
}

$div = str_repeat('=', 100);

echo "$div\n";
echo "  Tiered Storage Benchmark — $iterations iterations (median)\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . "\n";
echo "  Subjects: PHP array  |  Judy direct-to-tree  |  Judy tiered (tier 0/1/2)\n";
echo "$div\n\n";

// Sizes that matter for Judy: medium to large
$sizes = [100, 500, 1000, 5000, 10000, 50000, 100000, 500000];

// ═══════════════════════════════════════════════════════════════════════════
// 1. INT_TO_INT — Lifecycle: create + fill(N) + read + destroy
// ═══════════════════════════════════════════════════════════════════════════

echo "--- INT_TO_INT Lifecycle: create + fill(N) + read + destroy ---\n\n";

printf("  %9s  %14s  %14s  %14s  %8s\n",
    'N', 'PHP array', 'Judy direct', 'Judy tiered', 'Tiered Δ');
printf("  %9s  %14s  %14s  %14s  %8s\n",
    str_repeat('-', 9), str_repeat('-', 14), str_repeat('-', 14), str_repeat('-', 14), str_repeat('-', 8));

foreach ($sizes as $n) {
    $reps = max(1, min(50000, intdiv(2000000, $n + 1)));

    // PHP array
    $php_t = bench_median(function() use ($n, $reps) {
        for ($r = 0; $r < $reps; $r++) {
            $a = [];
            for ($k = 0; $k < $n; $k++) { $a[$k] = $k * 10; }
            $v = $a[$n - 1];
            unset($a);
        }
    }, $iterations);

    // Judy direct-to-tree (tiered_storage=0)
    ini_set('judy.tiered_storage', '0');
    $direct_t = bench_median(function() use ($n, $reps) {
        for ($r = 0; $r < $reps; $r++) {
            $j = new Judy(Judy::INT_TO_INT);
            for ($k = 0; $k < $n; $k++) { $j[$k] = $k * 10; }
            $v = $j[$n - 1];
            unset($j);
        }
    }, $iterations);

    // Judy tiered (tiered_storage=1)
    ini_set('judy.tiered_storage', '1');
    $tiered_t = bench_median(function() use ($n, $reps) {
        for ($r = 0; $r < $reps; $r++) {
            $j = new Judy(Judy::INT_TO_INT);
            for ($k = 0; $k < $n; $k++) { $j[$k] = $k * 10; }
            $v = $j[$n - 1];
            unset($j);
        }
    }, $iterations);

    printf("  %9s  %s  %s  %s  %s\n",
        number_format($n), fmt($php_t), fmt($direct_t), fmt($tiered_t),
        change_str($direct_t, $tiered_t));
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// 2. INT_TO_INT — Random read from pre-populated arrays
// ═══════════════════════════════════════════════════════════════════════════

echo "--- INT_TO_INT Random read: N lookups into array of N elements ---\n\n";

printf("  %9s  %14s  %14s  %14s  %8s\n",
    'N', 'PHP array', 'Judy direct', 'Judy tiered', 'Tiered Δ');
printf("  %9s  %14s  %14s  %14s  %8s\n",
    str_repeat('-', 9), str_repeat('-', 14), str_repeat('-', 14), str_repeat('-', 14), str_repeat('-', 8));

foreach ($sizes as $n) {
    $reps = max(1, min(50000, intdiv(5000000, $n + 1)));

    $indices = range(0, $n - 1);
    shuffle($indices);

    // Pre-populate
    $a = [];
    for ($k = 0; $k < $n; $k++) { $a[$k] = $k * 10; }

    ini_set('judy.tiered_storage', '0');
    $j_direct = new Judy(Judy::INT_TO_INT);
    for ($k = 0; $k < $n; $k++) { $j_direct[$k] = $k * 10; }

    ini_set('judy.tiered_storage', '1');
    $j_tiered = new Judy(Judy::INT_TO_INT);
    for ($k = 0; $k < $n; $k++) { $j_tiered[$k] = $k * 10; }

    $php_t = bench_median(function() use ($a, $indices, $reps) {
        for ($r = 0; $r < $reps; $r++) {
            $sum = 0;
            foreach ($indices as $i) { $sum += $a[$i]; }
        }
    }, $iterations);

    $direct_t = bench_median(function() use ($j_direct, $indices, $reps) {
        for ($r = 0; $r < $reps; $r++) {
            $sum = 0;
            foreach ($indices as $i) { $sum += $j_direct[$i]; }
        }
    }, $iterations);

    $tiered_t = bench_median(function() use ($j_tiered, $indices, $reps) {
        for ($r = 0; $r < $reps; $r++) {
            $sum = 0;
            foreach ($indices as $i) { $sum += $j_tiered[$i]; }
        }
    }, $iterations);

    printf("  %9s  %s  %s  %s  %s\n",
        number_format($n), fmt($php_t), fmt($direct_t), fmt($tiered_t),
        change_str($direct_t, $tiered_t));

    unset($a, $j_direct, $j_tiered);
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// 3. STRING_TO_INT — Lifecycle
// ═══════════════════════════════════════════════════════════════════════════

echo "--- STRING_TO_INT Lifecycle: create + fill(N) + read + destroy ---\n\n";

printf("  %9s  %14s  %14s  %14s  %8s\n",
    'N', 'PHP array', 'Judy direct', 'Judy tiered', 'Tiered Δ');
printf("  %9s  %14s  %14s  %14s  %8s\n",
    str_repeat('-', 9), str_repeat('-', 14), str_repeat('-', 14), str_repeat('-', 14), str_repeat('-', 8));

$str_sizes = [100, 1000, 10000, 100000, 500000];

foreach ($str_sizes as $n) {
    $reps = max(1, min(50000, intdiv(1000000, $n + 1)));

    $keys = [];
    for ($k = 0; $k < $n; $k++) { $keys[$k] = "k_$k"; }

    $php_t = bench_median(function() use ($n, $reps, $keys) {
        for ($r = 0; $r < $reps; $r++) {
            $a = [];
            for ($k = 0; $k < $n; $k++) { $a[$keys[$k]] = $k * 10; }
            $v = $a[$keys[$n - 1]];
            unset($a);
        }
    }, $iterations);

    ini_set('judy.tiered_storage', '0');
    $direct_t = bench_median(function() use ($n, $reps, $keys) {
        for ($r = 0; $r < $reps; $r++) {
            $j = new Judy(Judy::STRING_TO_INT);
            for ($k = 0; $k < $n; $k++) { $j[$keys[$k]] = $k * 10; }
            $v = $j[$keys[$n - 1]];
            unset($j);
        }
    }, $iterations);

    ini_set('judy.tiered_storage', '1');
    $tiered_t = bench_median(function() use ($n, $reps, $keys) {
        for ($r = 0; $r < $reps; $r++) {
            $j = new Judy(Judy::STRING_TO_INT);
            for ($k = 0; $k < $n; $k++) { $j[$keys[$k]] = $k * 10; }
            $v = $j[$keys[$n - 1]];
            unset($j);
        }
    }, $iterations);

    printf("  %9s  %s  %s  %s  %s\n",
        number_format($n), fmt($php_t), fmt($direct_t), fmt($tiered_t),
        change_str($direct_t, $tiered_t));
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// 4. Memory usage: PHP array vs Judy at selected sizes
// ═══════════════════════════════════════════════════════════════════════════

echo "--- Memory usage: PHP array vs Judy INT_TO_INT ---\n\n";

$mem_sizes = [1000, 10000, 100000, 500000];

printf("  %9s  %14s  %14s  %10s\n",
    'N', 'PHP array', 'Judy I→I', 'Savings');
printf("  %9s  %14s  %14s  %10s\n",
    str_repeat('-', 9), str_repeat('-', 14), str_repeat('-', 14), str_repeat('-', 10));

ini_set('judy.tiered_storage', '1');
foreach ($mem_sizes as $n) {
    gc_collect_cycles();
    $base = memory_get_usage();
    $a = [];
    for ($k = 0; $k < $n; $k++) { $a[$k] = $k * 10; }
    $php_mem = memory_get_usage() - $base;
    unset($a);

    gc_collect_cycles();
    $base = memory_get_usage();
    $j = new Judy(Judy::INT_TO_INT);
    for ($k = 0; $k < $n; $k++) { $j[$k] = $k * 10; }
    $judy_mem = $j->memoryUsage() ?: (memory_get_usage() - $base);
    unset($j);

    $pct = $php_mem > 0 ? sprintf('%8.0f%%', (1 - $judy_mem / $php_mem) * 100) : '     n/a';
    printf("  %9s  %s  %s  %s\n",
        number_format($n), fmt_mem($php_mem), fmt_mem($judy_mem), $pct);
}

echo "\n";

// ── Summary ──────────────────────────────────────────────────────────────

echo "$div\n";
echo "  Tiered Storage Benchmark complete — " . date('Y-m-d H:i:s') . "\n";
echo "  Tiered Δ = % change from direct-to-tree (negative = tiered is faster)\n";
echo "  Tiered storage: tier 0 inline (N<=8) → tier 2 Judy tree (N>8)\n";
echo "$div\n";
