<?php
/**
 * Benchmark: Native Judy API Methods vs PHP Userland Equivalents
 *
 * Compares native C-level Judy methods against equivalent PHP foreach loops:
 *
 *   1. keys()            vs  foreach + append key
 *   2. values()          vs  foreach + append value
 *   3. sumValues()       vs  foreach + accumulate
 *   4. populationCount() vs  manual range iteration count
 *   5. deleteRange()     vs  foreach + unset in range
 *   6. equals()          vs  manual element-by-element comparison
 *
 * Usage:
 *   php examples/judy-bench-phase2-api.php [size] [iterations]
 *   php examples/judy-bench-phase2-api.php 500000 5
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
function ratio(float $php, float $judy): string {
    if ($judy <= 0) return '       n/a';
    return sprintf('%9.1fx', $php / $judy);
}

$div  = str_repeat('━', 100);
$dash = str_repeat('─', 100);

echo "$div\n";
echo "  Native API Benchmark — " . number_format($size) . " elements, $iterations iterations (median)\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . "\n";
echo "$div\n\n";

// ── Populate test arrays ───────────────────────────────────────────────────

$j_int = new Judy(Judy::INT_TO_INT);
$j_str = new Judy(Judy::STRING_TO_INT);
$j_bit = new Judy(Judy::BITSET);
for ($i = 0; $i < $size; $i++) {
    $j_int[$i] = $i * 3;
    $j_str["key_$i"] = $i * 3;
    $j_bit[$i] = true;
}

// ── 1. keys() ──────────────────────────────────────────────────────────────

echo "┌─ keys() ─────────────────────────────────────────────────────────────────────┐\n\n";

$w = [22, 14, 14, 12];
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n", 'Type', 'PHP foreach', 'Judy keys()', 'Speedup');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]), str_repeat('─', $w[1]), str_repeat('─', $w[2]), str_repeat('─', $w[3]));

// INT_TO_INT keys
$php_t = bench_median(function() use ($j_int) {
    $keys = []; foreach ($j_int as $k => $v) { $keys[] = $k; }
}, $iterations);
$judy_t = bench_median(function() use ($j_int) { $j_int->keys(); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'INT_TO_INT', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// STRING_TO_INT keys
$php_t = bench_median(function() use ($j_str) {
    $keys = []; foreach ($j_str as $k => $v) { $keys[] = $k; }
}, $iterations);
$judy_t = bench_median(function() use ($j_str) { $j_str->keys(); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'STRING_TO_INT', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// BITSET keys
$php_t = bench_median(function() use ($j_bit) {
    $keys = []; foreach ($j_bit as $k => $v) { $keys[] = $k; }
}, $iterations);
$judy_t = bench_median(function() use ($j_bit) { $j_bit->keys(); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'BITSET', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

echo "\n";

// ── 2. values() ────────────────────────────────────────────────────────────

echo "┌─ values() ───────────────────────────────────────────────────────────────────┐\n\n";

printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n", 'Type', 'PHP foreach', 'Judy values()', 'Speedup');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]), str_repeat('─', $w[1]), str_repeat('─', $w[2]), str_repeat('─', $w[3]));

$php_t = bench_median(function() use ($j_int) {
    $vals = []; foreach ($j_int as $k => $v) { $vals[] = $v; }
}, $iterations);
$judy_t = bench_median(function() use ($j_int) { $j_int->values(); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'INT_TO_INT', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

$php_t = bench_median(function() use ($j_str) {
    $vals = []; foreach ($j_str as $k => $v) { $vals[] = $v; }
}, $iterations);
$judy_t = bench_median(function() use ($j_str) { $j_str->values(); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'STRING_TO_INT', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

echo "\n";

// ── 3. sumValues() ─────────────────────────────────────────────────────────

echo "┌─ sumValues() ────────────────────────────────────────────────────────────────┐\n\n";

printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n", 'Type', 'PHP foreach', 'Judy sum()', 'Speedup');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]), str_repeat('─', $w[1]), str_repeat('─', $w[2]), str_repeat('─', $w[3]));

$php_t = bench_median(function() use ($j_int) {
    $sum = 0; foreach ($j_int as $v) { $sum += $v; }
}, $iterations);
$judy_t = bench_median(function() use ($j_int) { $j_int->sumValues(); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'INT_TO_INT', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

$php_t = bench_median(function() use ($j_str) {
    $sum = 0; foreach ($j_str as $v) { $sum += $v; }
}, $iterations);
$judy_t = bench_median(function() use ($j_str) { $j_str->sumValues(); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'STRING_TO_INT', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// BITSET sumValues (= population count)
$php_t = bench_median(function() use ($j_bit) {
    $sum = 0; foreach ($j_bit as $v) { $sum += $v; }
}, $iterations);
$judy_t = bench_median(function() use ($j_bit) { $j_bit->sumValues(); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", 'BITSET', fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

echo "\n";

// ── 4. populationCount() ───────────────────────────────────────────────────
// This is the showpiece: Judy's O(1) internal population cache vs O(n) iteration

echo "┌─ populationCount(start, end) — Judy O(1) vs PHP O(n) ────────────────────────┐\n\n";

printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n", 'Range', 'PHP count', 'Judy popCnt()', 'Speedup');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]), str_repeat('─', $w[1]), str_repeat('─', $w[2]), str_repeat('─', $w[3]));

// Full range — INT_TO_INT
$php_t = bench_median(function() use ($j_int, $size) {
    $count = 0;
    foreach ($j_int as $k => $v) { $count++; }
}, $iterations);
$judy_t = bench_median(function() use ($j_int) { $j_int->populationCount(); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "INT full ($size)", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// Sub-range — INT_TO_INT (middle 50%)
$range_start = (int)($size * 0.25);
$range_end   = (int)($size * 0.75);
$php_t = bench_median(function() use ($j_int, $range_start, $range_end) {
    $count = 0;
    foreach ($j_int as $k => $v) {
        if ($k >= $range_start && $k <= $range_end) $count++;
        if ($k > $range_end) break;
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_int, $range_start, $range_end) {
    $j_int->populationCount($range_start, $range_end);
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "INT mid-50%", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// Full range — BITSET
$php_t = bench_median(function() use ($j_bit) {
    $count = 0;
    foreach ($j_bit as $k => $v) { $count++; }
}, $iterations);
$judy_t = bench_median(function() use ($j_bit) { $j_bit->populationCount(); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "BITSET full ($size)", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// Small sub-range — BITSET (first 1000)
$php_t = bench_median(function() use ($j_bit) {
    $count = 0;
    foreach ($j_bit as $k => $v) { if ($k > 999) break; $count++; }
}, $iterations);
$judy_t = bench_median(function() use ($j_bit) { $j_bit->populationCount(0, 999); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "BITSET 0-999", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

echo "\n";

// ── 5. deleteRange() ──────────────────────────────────────────────────────
// Must rebuild array each iteration since delete is destructive

echo "┌─ deleteRange() vs foreach+unset ─────────────────────────────────────────────┐\n\n";

printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n", 'Type / Range', 'PHP foreach', 'Judy delRange', 'Speedup');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]), str_repeat('─', $w[1]), str_repeat('─', $w[2]), str_repeat('─', $w[3]));

$del_start = (int)($size * 0.25);
$del_end   = (int)($size * 0.75);
$del_label = "INT mid-50%";

// INT_TO_INT deleteRange
$php_t = bench_median(function() use ($size, $del_start, $del_end) {
    $j = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $size; $i++) { $j[$i] = $i * 3; }
    foreach ($j as $k => $v) {
        if ($k >= $del_start && $k <= $del_end) unset($j[$k]);
        if ($k > $del_end) break;
    }
}, $iterations);
$judy_t = bench_median(function() use ($size, $del_start, $del_end) {
    $j = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $size; $i++) { $j[$i] = $i * 3; }
    $j->deleteRange($del_start, $del_end);
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", $del_label, fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// BITSET deleteRange
$php_t = bench_median(function() use ($size, $del_start, $del_end) {
    $j = new Judy(Judy::BITSET);
    for ($i = 0; $i < $size; $i++) { $j[$i] = true; }
    foreach ($j as $k => $v) {
        if ($k >= $del_start && $k <= $del_end) unset($j[$k]);
        if ($k > $del_end) break;
    }
}, $iterations);
$judy_t = bench_median(function() use ($size, $del_start, $del_end) {
    $j = new Judy(Judy::BITSET);
    for ($i = 0; $i < $size; $i++) { $j[$i] = true; }
    $j->deleteRange($del_start, $del_end);
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "BITSET mid-50%", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// STRING_TO_INT deleteRange — use lexicographic range
$str_del_start = "key_" . str_pad($del_start, 6, '0', STR_PAD_LEFT);
$str_del_end   = "key_" . str_pad($del_end, 6, '0', STR_PAD_LEFT);
$php_t = bench_median(function() use ($size, $str_del_start, $str_del_end) {
    $j = new Judy(Judy::STRING_TO_INT);
    for ($i = 0; $i < $size; $i++) {
        $j["key_" . str_pad($i, 6, '0', STR_PAD_LEFT)] = $i * 3;
    }
    foreach ($j as $k => $v) {
        if ($k >= $str_del_start && $k <= $str_del_end) unset($j[$k]);
        if ($k > $str_del_end) break;
    }
}, $iterations);
$judy_t = bench_median(function() use ($size, $str_del_start, $str_del_end) {
    $j = new Judy(Judy::STRING_TO_INT);
    for ($i = 0; $i < $size; $i++) {
        $j["key_" . str_pad($i, 6, '0', STR_PAD_LEFT)] = $i * 3;
    }
    $j->deleteRange($str_del_start, $str_del_end);
}, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "STRING_TO_INT mid-50%", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

echo "\n";

// ── 6. equals() ────────────────────────────────────────────────────────────

echo "┌─ equals() vs manual comparison ──────────────────────────────────────────────┐\n\n";

printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n", 'Scenario', 'PHP manual', 'Judy equals()', 'Speedup');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]), str_repeat('─', $w[1]), str_repeat('─', $w[2]), str_repeat('─', $w[3]));

// Clone for equal arrays
$j_int_b = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < $size; $i++) { $j_int_b[$i] = $i * 3; }

// Equal arrays — worst case: must compare every element
$php_t = bench_median(function() use ($j_int, $j_int_b, $size) {
    $equal = (count($j_int) === count($j_int_b));
    if ($equal) {
        foreach ($j_int as $k => $v) {
            if (!isset($j_int_b[$k]) || $j_int_b[$k] !== $v) { $equal = false; break; }
        }
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_int, $j_int_b) { $j_int->equals($j_int_b); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "INT equal ($size)", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// Different at element 0 — best case: early exit
$j_int_diff = new Judy(Judy::INT_TO_INT);
$j_int_diff[0] = 999999;
for ($i = 1; $i < $size; $i++) { $j_int_diff[$i] = $i * 3; }

$php_t = bench_median(function() use ($j_int, $j_int_diff, $size) {
    $equal = (count($j_int) === count($j_int_diff));
    if ($equal) {
        foreach ($j_int as $k => $v) {
            if (!isset($j_int_diff[$k]) || $j_int_diff[$k] !== $v) { $equal = false; break; }
        }
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_int, $j_int_diff) { $j_int->equals($j_int_diff); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "INT diff@0 (early)", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// Identity — self-comparison
$php_t = bench_median(function() use ($j_int) {
    $b = $j_int; // same reference
    $equal = (count($j_int) === count($b));
    if ($equal) {
        foreach ($j_int as $k => $v) {
            if (!isset($b[$k]) || $b[$k] !== $v) { $equal = false; break; }
        }
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_int) { $j_int->equals($j_int); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "INT identity (self)", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// BITSET equals
$j_bit_b = new Judy(Judy::BITSET);
for ($i = 0; $i < $size; $i++) { $j_bit_b[$i] = true; }

$php_t = bench_median(function() use ($j_bit, $j_bit_b) {
    $equal = (count($j_bit) === count($j_bit_b));
    if ($equal) {
        foreach ($j_bit as $k => $v) {
            if (!isset($j_bit_b[$k])) { $equal = false; break; }
        }
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_bit, $j_bit_b) { $j_bit->equals($j_bit_b); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "BITSET equal ($size)", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

// STRING_TO_INT equals
$j_str_b = new Judy(Judy::STRING_TO_INT);
for ($i = 0; $i < $size; $i++) { $j_str_b["key_$i"] = $i * 3; }

$php_t = bench_median(function() use ($j_str, $j_str_b) {
    $equal = (count($j_str) === count($j_str_b));
    if ($equal) {
        foreach ($j_str as $k => $v) {
            if (!isset($j_str_b[$k]) || $j_str_b[$k] !== $v) { $equal = false; break; }
        }
    }
}, $iterations);
$judy_t = bench_median(function() use ($j_str, $j_str_b) { $j_str->equals($j_str_b); }, $iterations);
printf("  %-{$w[0]}s  %s  %s  %s\n", "STR_TO_INT equal", fmt($php_t), fmt($judy_t), ratio($php_t, $judy_t));

echo "\n";

echo "$div\n";
echo "  Native API Benchmark complete — " . date('Y-m-d H:i:s') . "\n";
echo "  All speedups = PHP_userland_time / Judy_native_time (higher = better)\n";
echo "$div\n";
