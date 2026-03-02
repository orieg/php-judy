<?php
/**
 * Benchmark: Advanced Performance & Adaptive Types (SSO)
 *
 * Compares:
 *   1. forEach() (C-level)  vs  foreach loop (PHP iterator protocol)
 *   2. filter() (C-level)   vs  PHP foreach + conditional insert
 *   3. map() (C-level)      vs  PHP foreach + transform + insert
 *   4. String set operations: union/intersect/diff on STRING_TO_INT
 *   5. Adaptive SSO types   vs  regular string types (insert/read/iterate)
 *
 * Usage:
 *   php examples/judy-bench-phase3-advanced.php [size] [iterations]
 *   php examples/judy-bench-phase3-advanced.php 500000 5
 */

ini_set('memory_limit', '2G');

$size       = isset($argv[1]) ? (int)$argv[1] : 500000;
$iterations = isset($argv[2]) ? (int)$argv[2] : 5;

// ── Helpers ────────────────────────────────────────────────────────────────

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
function ratio(float $baseline, float $optimized): string {
    if ($optimized <= 0) return '       n/a';
    return sprintf('%9.1fx', $baseline / $optimized);
}

$div  = str_repeat('━', 100);
$dash = str_repeat('─', 100);

echo "$div\n";
echo "  Advanced Benchmark — " . number_format($size) . " elements, $iterations iterations (median)\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . "\n";
echo "$div\n\n";

$w = [24, 14, 14, 12];

// ── Populate test arrays ─────────────────────────────────────────────────

$j_int  = new Judy(Judy::INT_TO_INT);
$j_str  = new Judy(Judy::STRING_TO_INT);
for ($i = 0; $i < $size; $i++) {
    $j_int[$i] = $i * 3;
    $j_str["key_$i"] = $i * 3;
}

// ══════════════════════════════════════════════════════════════════════════
// 1. forEach() — C-level callback vs PHP iterator protocol
// ══════════════════════════════════════════════════════════════════════════

echo "┌─ forEach() — C-level callback vs PHP foreach ──────────────────────────────┐\n\n";

printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    'Type', 'PHP foreach', 'C forEach()', 'Speedup');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]), str_repeat('─', $w[1]), str_repeat('─', $w[2]), str_repeat('─', $w[3]));

// INT_TO_INT forEach
$php_t = bench_median(function() use ($j_int) {
    $sum = 0;
    foreach ($j_int as $k => $v) { $sum += $v; }
}, $iterations);
$judy_t = bench_median(function() use ($j_int) {
    $sum = 0;
    $j_int->forEach(function($v, $k) use (&$sum) { $sum += $v; });
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'INT_TO_INT', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// STRING_TO_INT forEach
$php_t = bench_median(function() use ($j_str) {
    $sum = 0;
    foreach ($j_str as $k => $v) { $sum += $v; }
}, $iterations);
$judy_t = bench_median(function() use ($j_str) {
    $sum = 0;
    $j_str->forEach(function($v, $k) use (&$sum) { $sum += $v; });
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'STRING_TO_INT', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// 2. filter() — C-level filter vs PHP foreach + conditional insert
// ══════════════════════════════════════════════════════════════════════════

echo "┌─ filter() — C-level filter vs PHP foreach + insert ────────────────────────┐\n\n";

printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    'Type', 'PHP manual', 'C filter()', 'Speedup');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]), str_repeat('─', $w[1]), str_repeat('─', $w[2]), str_repeat('─', $w[3]));

// INT_TO_INT filter (keep even values)
$php_t = bench_median(function() use ($j_int) {
    $result = new Judy(Judy::INT_TO_INT);
    foreach ($j_int as $k => $v) {
        if ($v % 2 === 0) $result[$k] = $v;
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_int) {
    $j_int->filter(function($v) { return $v % 2 === 0; });
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'INT_TO_INT (50% pass)', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// STRING_TO_INT filter
$php_t = bench_median(function() use ($j_str) {
    $result = new Judy(Judy::STRING_TO_INT);
    foreach ($j_str as $k => $v) {
        if ($v % 2 === 0) $result[$k] = $v;
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_str) {
    $j_str->filter(function($v) { return $v % 2 === 0; });
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'STRING_TO_INT (50% pass)', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// 3. map() — C-level map vs PHP foreach + transform + insert
// ══════════════════════════════════════════════════════════════════════════

echo "┌─ map() — C-level map vs PHP foreach + transform ───────────────────────────┐\n\n";

printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    'Type', 'PHP manual', 'C map()', 'Speedup');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]), str_repeat('─', $w[1]), str_repeat('─', $w[2]), str_repeat('─', $w[3]));

// INT_TO_INT map (multiply by 2)
$php_t = bench_median(function() use ($j_int) {
    $result = new Judy(Judy::INT_TO_INT);
    foreach ($j_int as $k => $v) {
        $result[$k] = $v * 2;
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_int) {
    $j_int->map(function($v) { return $v * 2; });
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'INT_TO_INT (*2)', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// STRING_TO_INT map
$php_t = bench_median(function() use ($j_str) {
    $result = new Judy(Judy::STRING_TO_INT);
    foreach ($j_str as $k => $v) {
        $result[$k] = $v * 2;
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_str) {
    $j_str->map(function($v) { return $v * 2; });
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'STRING_TO_INT (*2)', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// 4. String set operations — union/intersect/diff on STRING_TO_INT
// ══════════════════════════════════════════════════════════════════════════

// Setup: two overlapping STRING_TO_INT arrays
$j_str_a = new Judy(Judy::STRING_TO_INT);
$j_str_b = new Judy(Judy::STRING_TO_INT);
$half = (int)($size / 2);
for ($i = 0; $i < $size; $i++) {
    $j_str_a["k_$i"] = $i;
    $j_str_b["k_" . ($i + $half)] = $i + $size;
}

echo "┌─ String set ops — STRING_TO_INT union/intersect/diff ──────────────────────┐\n\n";

printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    'Operation', 'PHP manual', 'Judy native', 'Speedup');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]), str_repeat('─', $w[1]), str_repeat('─', $w[2]), str_repeat('─', $w[3]));

// union
$php_t = bench_median(function() use ($j_str_a, $j_str_b) {
    $result = new Judy(Judy::STRING_TO_INT);
    foreach ($j_str_b as $k => $v) { $result[$k] = $v; }
    foreach ($j_str_a as $k => $v) { $result[$k] = $v; }
}, $iterations);
$judy_t = bench_median(function() use ($j_str_a, $j_str_b) {
    $j_str_a->union($j_str_b);
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "union ($size + 50%ovlp)", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// intersect
$php_t = bench_median(function() use ($j_str_a, $j_str_b) {
    $result = new Judy(Judy::STRING_TO_INT);
    foreach ($j_str_a as $k => $v) {
        if (isset($j_str_b[$k])) $result[$k] = $v;
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_str_a, $j_str_b) {
    $j_str_a->intersect($j_str_b);
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "intersect (50% overlap)", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// diff
$php_t = bench_median(function() use ($j_str_a, $j_str_b) {
    $result = new Judy(Judy::STRING_TO_INT);
    foreach ($j_str_a as $k => $v) {
        if (!isset($j_str_b[$k])) $result[$k] = $v;
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_str_a, $j_str_b) {
    $j_str_a->diff($j_str_b);
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "diff (self - other)", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// 5. Adaptive SSO types vs regular string types
// ══════════════════════════════════════════════════════════════════════════

echo "┌─ Adaptive SSO — short keys (<8 bytes) via JudyL vs JudySL/JudyHS ─────────┐\n\n";

$w2 = [24, 14, 14, 14, 12];
printf("  %-{$w2[0]}s  %{$w2[1]}s  %{$w2[2]}s  %{$w2[3]}s  %{$w2[4]}s\n",
    'Operation', 'STR_TO_INT', 'STR_INT_HASH', 'STR_INT_ADPTV', 'Best ratio');
printf("  %-{$w2[0]}s  %{$w2[1]}s  %{$w2[2]}s  %{$w2[3]}s  %{$w2[4]}s\n",
    str_repeat('─', $w2[0]), str_repeat('─', $w2[1]), str_repeat('─', $w2[2]), str_repeat('─', $w2[3]), str_repeat('─', $w2[4]));

// Use short keys (3-6 chars) to exercise SSO path
$short_keys = [];
for ($i = 0; $i < $size; $i++) {
    $short_keys[] = base_convert($i, 10, 36); // short alphanumeric keys
}

// INSERT — short keys
$t_sl = bench_median(function() use ($short_keys, $size) {
    $j = new Judy(Judy::STRING_TO_INT);
    for ($i = 0; $i < $size; $i++) { $j[$short_keys[$i]] = $i; }
}, $iterations);
$t_hs = bench_median(function() use ($short_keys, $size) {
    $j = new Judy(Judy::STRING_TO_INT_HASH);
    for ($i = 0; $i < $size; $i++) { $j[$short_keys[$i]] = $i; }
}, $iterations);
$t_ad = bench_median(function() use ($short_keys, $size) {
    $j = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
    for ($i = 0; $i < $size; $i++) { $j[$short_keys[$i]] = $i; }
}, $iterations);
$best = min($t_sl, $t_hs, $t_ad);
printf("  %-{$w2[0]}s  %s  %s  %s  %s\n",
    "insert (short keys)", fmt($t_sl), fmt($t_hs), fmt($t_ad), ratio(max($t_sl, $t_hs), $best));

// Pre-populate for read/iterate benchmarks
$j_sl = new Judy(Judy::STRING_TO_INT);
$j_hs = new Judy(Judy::STRING_TO_INT_HASH);
$j_ad = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
for ($i = 0; $i < $size; $i++) {
    $j_sl[$short_keys[$i]] = $i;
    $j_hs[$short_keys[$i]] = $i;
    $j_ad[$short_keys[$i]] = $i;
}

// READ — random-access short keys
$read_keys = $short_keys;
shuffle($read_keys);
$read_subset = array_slice($read_keys, 0, min($size, 100000));

$t_sl = bench_median(function() use ($j_sl, $read_subset) {
    $sum = 0; foreach ($read_subset as $k) { $sum += $j_sl[$k]; }
}, $iterations);
$t_hs = bench_median(function() use ($j_hs, $read_subset) {
    $sum = 0; foreach ($read_subset as $k) { $sum += $j_hs[$k]; }
}, $iterations);
$t_ad = bench_median(function() use ($j_ad, $read_subset) {
    $sum = 0; foreach ($read_subset as $k) { $sum += $j_ad[$k]; }
}, $iterations);
$best = min($t_sl, $t_hs, $t_ad);
printf("  %-{$w2[0]}s  %s  %s  %s  %s\n",
    "read (random access)", fmt($t_sl), fmt($t_hs), fmt($t_ad), ratio(max($t_sl, $t_hs), $best));

// ITERATE — foreach over all elements
$t_sl = bench_median(function() use ($j_sl) {
    $sum = 0; foreach ($j_sl as $k => $v) { $sum += $v; }
}, $iterations);
$t_hs = bench_median(function() use ($j_hs) {
    $sum = 0; foreach ($j_hs as $k => $v) { $sum += $v; }
}, $iterations);
$t_ad = bench_median(function() use ($j_ad) {
    $sum = 0; foreach ($j_ad as $k => $v) { $sum += $v; }
}, $iterations);
$best = min($t_sl, $t_hs, $t_ad);
printf("  %-{$w2[0]}s  %s  %s  %s  %s\n",
    "foreach (iterate all)", fmt($t_sl), fmt($t_hs), fmt($t_ad), ratio(max($t_sl, $t_hs), $best));

echo "\n";

// Long keys (>= 8 bytes) — where adaptive falls back to JudyHS
echo "┌─ Adaptive SSO — long keys (>=8 bytes) — fallback to JudyHS ────────────────┐\n\n";

printf("  %-{$w2[0]}s  %{$w2[1]}s  %{$w2[2]}s  %{$w2[3]}s  %{$w2[4]}s\n",
    'Operation', 'STR_TO_INT', 'STR_INT_HASH', 'STR_INT_ADPTV', 'Best ratio');
printf("  %-{$w2[0]}s  %{$w2[1]}s  %{$w2[2]}s  %{$w2[3]}s  %{$w2[4]}s\n",
    str_repeat('─', $w2[0]), str_repeat('─', $w2[1]), str_repeat('─', $w2[2]), str_repeat('─', $w2[3]), str_repeat('─', $w2[4]));

$long_keys = [];
for ($i = 0; $i < $size; $i++) {
    $long_keys[] = "long_key_prefix_" . str_pad($i, 8, '0', STR_PAD_LEFT);
}

// INSERT — long keys
$t_sl = bench_median(function() use ($long_keys, $size) {
    $j = new Judy(Judy::STRING_TO_INT);
    for ($i = 0; $i < $size; $i++) { $j[$long_keys[$i]] = $i; }
}, $iterations);
$t_hs = bench_median(function() use ($long_keys, $size) {
    $j = new Judy(Judy::STRING_TO_INT_HASH);
    for ($i = 0; $i < $size; $i++) { $j[$long_keys[$i]] = $i; }
}, $iterations);
$t_ad = bench_median(function() use ($long_keys, $size) {
    $j = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
    for ($i = 0; $i < $size; $i++) { $j[$long_keys[$i]] = $i; }
}, $iterations);
$best = min($t_sl, $t_hs, $t_ad);
printf("  %-{$w2[0]}s  %s  %s  %s  %s\n",
    "insert (long keys)", fmt($t_sl), fmt($t_hs), fmt($t_ad), ratio(max($t_sl, $t_hs), $best));

// Pre-populate for read benchmark
$jl_sl = new Judy(Judy::STRING_TO_INT);
$jl_hs = new Judy(Judy::STRING_TO_INT_HASH);
$jl_ad = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
for ($i = 0; $i < $size; $i++) {
    $jl_sl[$long_keys[$i]] = $i;
    $jl_hs[$long_keys[$i]] = $i;
    $jl_ad[$long_keys[$i]] = $i;
}

// READ — random-access long keys
$lr_keys = $long_keys;
shuffle($lr_keys);
$lr_subset = array_slice($lr_keys, 0, min($size, 100000));

$t_sl = bench_median(function() use ($jl_sl, $lr_subset) {
    $sum = 0; foreach ($lr_subset as $k) { $sum += $jl_sl[$k]; }
}, $iterations);
$t_hs = bench_median(function() use ($jl_hs, $lr_subset) {
    $sum = 0; foreach ($lr_subset as $k) { $sum += $jl_hs[$k]; }
}, $iterations);
$t_ad = bench_median(function() use ($jl_ad, $lr_subset) {
    $sum = 0; foreach ($lr_subset as $k) { $sum += $jl_ad[$k]; }
}, $iterations);
$best = min($t_sl, $t_hs, $t_ad);
printf("  %-{$w2[0]}s  %s  %s  %s  %s\n",
    "read (random access)", fmt($t_sl), fmt($t_hs), fmt($t_ad), ratio(max($t_sl, $t_hs), $best));

// ITERATE — foreach long keys
$t_sl = bench_median(function() use ($jl_sl) {
    $sum = 0; foreach ($jl_sl as $k => $v) { $sum += $v; }
}, $iterations);
$t_hs = bench_median(function() use ($jl_hs) {
    $sum = 0; foreach ($jl_hs as $k => $v) { $sum += $v; }
}, $iterations);
$t_ad = bench_median(function() use ($jl_ad) {
    $sum = 0; foreach ($jl_ad as $k => $v) { $sum += $v; }
}, $iterations);
$best = min($t_sl, $t_hs, $t_ad);
printf("  %-{$w2[0]}s  %s  %s  %s  %s\n",
    "foreach (iterate all)", fmt($t_sl), fmt($t_hs), fmt($t_ad), ratio(max($t_sl, $t_hs), $best));

echo "\n";

// ── Summary ─────────────────────────────────────────────────────────────

echo "$div\n";
echo "  Advanced Benchmark complete — " . date('Y-m-d H:i:s') . "\n";
echo "  Speedup = baseline_time / optimized_time (higher = better)\n";
echo "$div\n";
