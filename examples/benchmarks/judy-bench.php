<?php
/**
 * Unified Benchmark Suite for php-judy
 *
 * Consolidates all benchmark scripts into a single runner with structured
 * JSON output for cross-version comparison.
 *
 * Suites:
 *   core     — All Judy types + PHP array: write, read, foreach, free, memory
 *   api      — Batch ops, set ops, keys/values, sumValues, populationCount,
 *              deleteRange, equals
 *   advanced — C-level forEach/filter/map, the optimizeIteration trade,
 *              adaptive SSO comparison
 *   all      — All of the above (default)
 *
 * Usage:
 *   php judy-bench.php [--json output.json] [--size N] [--iterations N] [--suite SUITE]
 *
 * Examples:
 *   php judy-bench.php --size 100000 --iterations 3
 *   php judy-bench.php --json bench.json --suite core
 *   php judy-bench.php --json bench.json --size 500000 --iterations 5 --suite all
 */

ini_set('memory_limit', '2G');

// ── CLI argument parsing ────────────────────────────────────────────────────

$opts = getopt('', ['json:', 'size:', 'iterations:', 'suite:', 'group:', 'list-groups']);
$size       = isset($opts['size'])       ? (int)$opts['size']       : 500000;
$iterations = isset($opts['iterations']) ? (int)$opts['iterations'] : 5;
$suite      = isset($opts['suite'])      ? $opts['suite']           : 'all';
$json_file  = isset($opts['json'])       ? $opts['json']            : null;

// Groups are the finest unit the runner can execute on its own. Each one owns
// its setup, so running a group in isolation costs only that group's work —
// which is what lets an external driver interleave two extension builds at
// group granularity instead of whole-suite granularity (see
// scripts/bench-compare.php).
$suite_groups = [
    'core'     => ['core.int', 'core.str'],
    'api'      => ['api.batch', 'api.setops'],
    'advanced' => ['adv.iter', 'adv.sso'],
];
$suite_groups['all'] = array_merge(...array_values($suite_groups));

if (isset($opts['list-groups'])) {
    foreach ($suite_groups['all'] as $g) {
        echo "$g\n";
    }
    exit(0);
}

$valid_suites = array_keys($suite_groups);
if (!in_array($suite, $valid_suites, true)) {
    fwrite(STDERR, "Invalid suite: $suite. Choose from: " . implode(', ', $valid_suites) . "\n");
    exit(1);
}

$active_groups = $suite_groups[$suite];
if (isset($opts['group'])) {
    $requested = array_filter(array_map('trim', explode(',', (string)$opts['group'])), 'strlen');
    $unknown   = array_diff($requested, $suite_groups['all']);
    if ($unknown) {
        fwrite(STDERR, "Unknown group(s): " . implode(', ', $unknown)
            . ". Choose from: " . implode(', ', $suite_groups['all']) . "\n");
        exit(1);
    }
    $active_groups = array_values($requested);
}
$active_group_set = array_flip($active_groups);

/** Is this benchmark group selected for this run? */
function bench_group(string $group): bool {
    global $active_group_set;
    return isset($active_group_set[$group]);
}

$run_core     = bench_group('core.int')  || bench_group('core.str');
$run_api      = bench_group('api.batch') || bench_group('api.setops');
$run_advanced = bench_group('adv.iter')  || bench_group('adv.sso');

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Run $fn $iterations times (after one warmup) and return [median_ms, all_ms].
 */
function bench_median(callable $fn, int $iterations): array {
    $fn(); // warmup
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start  = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);
    return ['median' => $times[intdiv($iterations, 2)], 'runs' => $times];
}

/**
 * Measure emalloc'd PHP heap consumed while $fn() runs.
 * $fn must return the created container.
 */
function measure_heap(callable $fn): int {
    gc_collect_cycles();
    $before = memory_get_usage();
    $ref = $fn();
    $after  = memory_get_usage();
    unset($ref);
    return max(0, $after - $before);
}

/**
 * Measure time to destroy (unset) a populated container.
 */
function measure_free(callable $create_fn, int $iterations): array {
    $create_fn(); // warmup
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $ref = $create_fn();
        gc_collect_cycles();
        $start = hrtime(true);
        unset($ref);
        gc_collect_cycles();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);
    return ['median' => $times[intdiv($iterations, 2)], 'runs' => $times];
}

function fmt_bytes(int $bytes): string {
    if ($bytes <= 0)       return '—';
    if ($bytes >= 1 << 20) return sprintf('%.1f MB', $bytes / (1 << 20));
    if ($bytes >= 1 << 10) return sprintf('%.1f KB', $bytes / (1 << 10));
    return $bytes . ' B';
}

function fmt_ms(float $ms): string {
    return sprintf('%10.2f ms', $ms);
}

function fmt_ratio(float $baseline, float $optimized): string {
    if ($optimized <= 0) return '      n/a';
    return sprintf('%8.1fx', $baseline / $optimized);
}

// ── Results collector ───────────────────────────────────────────────────────

$json_results = [
    'metadata' => [
        'php_version'  => phpversion(),
        'judy_version' => judy_version(),
        'platform'     => PHP_OS . ' ' . php_uname('m'),
        'date'         => date('Y-m-d\TH:i:sP'),
        'size'         => $size,
        'iterations'   => $iterations,
        'suite'        => $suite,
        'groups'       => $active_groups,
    ],
    'benchmarks' => [],
];

/**
 * Record a benchmark result into the JSON structure.
 * $id is a stable dotted key like "core.int_to_int.write".
 * $data has keys: median, runs (optional), heap (optional).
 */
function record(string $id, float $median_ms, array $runs = [], ?int $heap = null): void {
    global $json_results;
    $entry = ['median_ms' => round($median_ms, 4)];
    if (!empty($runs)) {
        $entry['runs_ms'] = array_map(fn($v) => round($v, 4), $runs);
    }
    if ($heap !== null) {
        $entry['heap_bytes'] = $heap;
    }
    $json_results['benchmarks'][$id] = $entry;
}

/**
 * Record what the optimizeIteration opt-in costs relative to the default
 * instance *of the same build*, as a percentage of the default.
 *
 * Why a ratio at all. An absolute optimized timing has no counterpart on the
 * release-comparison baseline arm — that arm runs the last PECL release, which
 * predates the API and therefore never emits these ids. Those absolutes land
 * in the comparison's "new" bucket and only become comparable from the first
 * release-over-release run after a release ships the feature. A ratio taken
 * inside one binary needs no baseline, so it is the half of this section that
 * answers "is the win still there?" today.
 *
 * Direction. Encoded so that worse is always larger, which is the direction
 * scripts/bench-compare.php reports as SLOWER:
 *   - traversal sits below 100 (76 == "optimized foreach costs 76% of
 *     default"). If the win erodes the value climbs toward 100; if it inverts
 *     it passes 100. Either way it rises, and a rise past the threshold is
 *     flagged.
 *   - writes sit above 100, the price of the trade. A deepening penalty also
 *     raises the value.
 *
 * Scale. Reported as a percentage rather than a bare ratio because
 * bench-compare.php skips any benchmark under its --min-ms floor (2.0 by
 * default) on both arms; a 0.76 would fall under it and never be evaluated.
 */
function record_optiter_ratio(string $op, string $type_id, float $default_ms, float $optimized_ms): void {
    if ($default_ms <= 0.0) {
        // Nothing meaningful to divide by. Emit no entry at all rather than a
        // zero that would read downstream as a real measurement.
        return;
    }
    record("adv.optiter.ratio.$op.$type_id.judy", $optimized_ms / $default_ms * 100.0);
}

// ── Detect available types ──────────────────────────────────────────────────

$has_packed  = defined('Judy::INT_TO_PACKED');
$has_hash    = defined('Judy::STRING_TO_INT_HASH');
$has_adaptive = defined('Judy::STRING_TO_INT_ADAPTIVE');

// The optimizeIteration opt-in landed after the last release, so the arm that
// scripts/bench-compare.php installs from PECL cannot construct an optimized
// instance at all. Probe the *class*, never the constructor: on a build
// without the feature `new Judy($t, optimizeIteration: true)` raises "Unknown
// named parameter $optimizeIteration", which would abort the baseline arm and
// take the whole release comparison down with it. isIterationOptimized() and
// the constructor argument shipped in the same commit, so the method's
// presence is exactly the feature's presence.
$has_optiter = method_exists('Judy', 'isIterationOptimized');

// ── Header ──────────────────────────────────────────────────────────────────

$div = str_repeat('═', 90);
echo "$div\n";
echo "  php-judy Benchmark Suite — " . number_format($size) . " elements, $iterations iterations (median)\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . " | " . PHP_OS . " " . php_uname('m') . "\n";
echo "  Suite: $suite | Groups: " . implode(',', $active_groups) . "\n";
echo "$div\n\n";

// ╔═══════════════════════════════════════════════════════════════════════════╗
// ║ CORE SUITE                                                               ║
// ╚═══════════════════════════════════════════════════════════════════════════╝

if ($run_core) {

// ── Core: Data generators ───────────────────────────────────────────────────

$core_types = [];

// Helper to bench a single core type
function bench_core_type(
    string $id_prefix,
    string $label,
    int $judy_type_or_neg,
    callable $populate_judy,
    callable $populate_php,
    callable $read_judy,
    callable $read_php,
    int $size,
    int $iterations
): array {
    // Write
    $w_judy = bench_median($populate_judy, $iterations);
    $w_php  = bench_median($populate_php, $iterations);

    // Read (requires pre-populated container)
    $r_judy = bench_median($read_judy, $iterations);
    $r_php  = bench_median($read_php, $iterations);

    // Foreach
    $j_ref = $populate_judy();
    $p_ref = $populate_php();
    $f_judy = bench_median(function() use ($j_ref) { foreach ($j_ref as $k => $v) {} }, $iterations);
    $f_php  = bench_median(function() use ($p_ref) { foreach ($p_ref as $k => $v) {} }, $iterations);

    // Free
    $fr_judy = measure_free($populate_judy, $iterations);
    $fr_php  = measure_free($populate_php, $iterations);

    // Heap
    $h_judy = measure_heap(function() use ($populate_judy) { return $populate_judy(); });
    $h_php  = measure_heap(function() use ($populate_php)  { return $populate_php(); });

    unset($j_ref, $p_ref);

    // Record JSON
    record("{$id_prefix}.write.judy", $w_judy['median'], $w_judy['runs']);
    record("{$id_prefix}.write.php",  $w_php['median'],  $w_php['runs']);
    record("{$id_prefix}.read.judy",  $r_judy['median'], $r_judy['runs']);
    record("{$id_prefix}.read.php",   $r_php['median'],  $r_php['runs']);
    record("{$id_prefix}.iter.judy",  $f_judy['median'], $f_judy['runs']);
    record("{$id_prefix}.iter.php",   $f_php['median'],  $f_php['runs']);
    record("{$id_prefix}.free.judy",  $fr_judy['median'], $fr_judy['runs']);
    record("{$id_prefix}.free.php",   $fr_php['median'],  $fr_php['runs']);
    record("{$id_prefix}.heap.judy",  0, [], $h_judy);
    record("{$id_prefix}.heap.php",   0, [], $h_php);

    return [
        'label'   => $label,
        'write'   => [$w_judy['median'], $w_php['median']],
        'read'    => [$r_judy['median'], $r_php['median']],
        'iter'    => [$f_judy['median'], $f_php['median']],
        'free'    => [$fr_judy['median'], $fr_php['median']],
        'heap'    => [$h_judy, $h_php],
    ];
}

$col = [24, 12, 12, 12, 12, 12, 12];

if (bench_group('core.int')) {

echo "── Core: Integer-Keyed Types ────────────────────────────────────────────────\n\n";

printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
    'Type', 'Write(ms)', 'Read(ms)', 'Iter(ms)', 'Free(ms)', 'Heap');
printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
    str_repeat('─', $col[0]),
    str_repeat('─', $col[1]), str_repeat('─', $col[2]),
    str_repeat('─', $col[3]), str_repeat('─', $col[4]),
    str_repeat('─', $col[5]));

// -- BITSET --
$j_pop = function() use ($size) {
    $j = new Judy(Judy::BITSET);
    for ($i = 0; $i < $size; $i++) { $j[$i] = true; }
    return $j;
};
$p_pop = function() use ($size) {
    $a = [];
    for ($i = 0; $i < $size; $i++) { $a[$i] = true; }
    return $a;
};
$j_ref_bs = $j_pop(); $p_ref_bs = $p_pop();
$r = bench_core_type('core.bitset', 'BITSET', Judy::BITSET,
    $j_pop, $p_pop,
    function() use ($j_ref_bs, $size) { for ($i = 0; $i < $size; $i++) { $v = $j_ref_bs[$i]; } },
    function() use ($p_ref_bs, $size) { for ($i = 0; $i < $size; $i++) { $v = $p_ref_bs[$i]; } },
    $size, $iterations);
printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
    'BITSET',
    sprintf('%.2f', $r['write'][0]), sprintf('%.2f', $r['read'][0]),
    sprintf('%.2f', $r['iter'][0]),  sprintf('%.2f', $r['free'][0]),
    fmt_bytes($r['heap'][0]));
printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
    '  PHP array',
    sprintf('%.2f', $r['write'][1]), sprintf('%.2f', $r['read'][1]),
    sprintf('%.2f', $r['iter'][1]),  sprintf('%.2f', $r['free'][1]),
    fmt_bytes($r['heap'][1]));
unset($j_ref_bs, $p_ref_bs);

// -- INT_TO_INT --
$j_pop = function() use ($size) {
    $j = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $size; $i++) { $j[$i] = $i; }
    return $j;
};
$p_pop = function() use ($size) {
    $a = [];
    for ($i = 0; $i < $size; $i++) { $a[$i] = $i; }
    return $a;
};
$j_ref_ii = $j_pop(); $p_ref_ii = $p_pop();
$r = bench_core_type('core.int_to_int', 'INT_TO_INT', Judy::INT_TO_INT,
    $j_pop, $p_pop,
    function() use ($j_ref_ii, $size) { for ($i = 0; $i < $size; $i++) { $v = $j_ref_ii[$i]; } },
    function() use ($p_ref_ii, $size) { for ($i = 0; $i < $size; $i++) { $v = $p_ref_ii[$i]; } },
    $size, $iterations);
printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
    'INT_TO_INT',
    sprintf('%.2f', $r['write'][0]), sprintf('%.2f', $r['read'][0]),
    sprintf('%.2f', $r['iter'][0]),  sprintf('%.2f', $r['free'][0]),
    fmt_bytes($r['heap'][0]));
printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
    '  PHP array',
    sprintf('%.2f', $r['write'][1]), sprintf('%.2f', $r['read'][1]),
    sprintf('%.2f', $r['iter'][1]),  sprintf('%.2f', $r['free'][1]),
    fmt_bytes($r['heap'][1]));
unset($j_ref_ii, $p_ref_ii);

// -- INT_TO_MIXED --
$mixed_data = [];
for ($i = 0; $i < $size; $i++) {
    switch ($i & 3) {
        case 0: $mixed_data[$i] = "str_$i"; break;
        case 1: $mixed_data[$i] = $i * 7; break;
        case 2: $mixed_data[$i] = [$i, $i + 1]; break;
        case 3: $mixed_data[$i] = ($i & 1) === 0; break;
    }
}
$mixed_keys = array_keys($mixed_data);
$j_pop = function() use ($mixed_data) {
    $j = new Judy(Judy::INT_TO_MIXED);
    foreach ($mixed_data as $k => $v) { $j[$k] = $v; }
    return $j;
};
$p_pop = function() use ($mixed_data) {
    $a = [];
    foreach ($mixed_data as $k => $v) { $a[$k] = $v; }
    return $a;
};
$j_ref_im = $j_pop(); $p_ref_im = $p_pop();
$r = bench_core_type('core.int_to_mixed', 'INT_TO_MIXED', Judy::INT_TO_MIXED,
    $j_pop, $p_pop,
    function() use ($j_ref_im, $mixed_keys) { foreach ($mixed_keys as $k) { $v = $j_ref_im[$k]; } },
    function() use ($p_ref_im, $mixed_keys) { foreach ($mixed_keys as $k) { $v = $p_ref_im[$k]; } },
    $size, $iterations);
printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
    'INT_TO_MIXED',
    sprintf('%.2f', $r['write'][0]), sprintf('%.2f', $r['read'][0]),
    sprintf('%.2f', $r['iter'][0]),  sprintf('%.2f', $r['free'][0]),
    fmt_bytes($r['heap'][0]));
printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
    '  PHP array',
    sprintf('%.2f', $r['write'][1]), sprintf('%.2f', $r['read'][1]),
    sprintf('%.2f', $r['iter'][1]),  sprintf('%.2f', $r['free'][1]),
    fmt_bytes($r['heap'][1]));
unset($j_ref_im, $p_ref_im);

// -- INT_TO_PACKED --
if ($has_packed) {
    $j_pop = function() use ($mixed_data) {
        $j = new Judy(Judy::INT_TO_PACKED);
        foreach ($mixed_data as $k => $v) { $j[$k] = $v; }
        return $j;
    };
    $p_pop_packed = function() use ($mixed_data) {
        $a = [];
        foreach ($mixed_data as $k => $v) { $a[$k] = $v; }
        return $a;
    };
    $j_ref_ip = $j_pop();
    $p_ref_ip = $p_pop_packed();
    $r = bench_core_type('core.int_to_packed', 'INT_TO_PACKED', Judy::INT_TO_PACKED,
        $j_pop, $p_pop_packed,
        function() use ($j_ref_ip, $mixed_keys) { foreach ($mixed_keys as $k) { $v = $j_ref_ip[$k]; } },
        function() use ($p_ref_ip, $mixed_keys) { foreach ($mixed_keys as $k) { $v = $p_ref_ip[$k]; } },
        $size, $iterations);
    printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
        'INT_TO_PACKED',
        sprintf('%.2f', $r['write'][0]), sprintf('%.2f', $r['read'][0]),
        sprintf('%.2f', $r['iter'][0]),  sprintf('%.2f', $r['free'][0]),
        fmt_bytes($r['heap'][0]));
    printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
        '  PHP array',
        sprintf('%.2f', $r['write'][1]), sprintf('%.2f', $r['read'][1]),
        sprintf('%.2f', $r['iter'][1]),  sprintf('%.2f', $r['free'][1]),
        fmt_bytes($r['heap'][1]));
    unset($j_ref_ip, $p_ref_ip);
}

unset($mixed_data, $mixed_keys);
echo "\n";

} // end core.int group

// ── Core: String-Keyed Types ────────────────────────────────────────────────

if (bench_group('core.str')) {

echo "── Core: String-Keyed Types ────────────────────────────────────────────────\n\n";

printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
    'Type', 'Write(ms)', 'Read(ms)', 'Iter(ms)', 'Free(ms)', 'Heap');
printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
    str_repeat('─', $col[0]),
    str_repeat('─', $col[1]), str_repeat('─', $col[2]),
    str_repeat('─', $col[3]), str_repeat('─', $col[4]),
    str_repeat('─', $col[5]));

// Common string data
$str_int_data = [];
for ($i = 0; $i < $size; $i++) { $str_int_data["key_$i"] = $i; }
$str_keys = array_keys($str_int_data);

$str_mixed_data = [];
for ($i = 0; $i < $size; $i++) {
    switch ($i & 3) {
        case 0: $str_mixed_data["key_$i"] = "str_$i"; break;
        case 1: $str_mixed_data["key_$i"] = $i * 7; break;
        case 2: $str_mixed_data["key_$i"] = [$i, $i + 1]; break;
        case 3: $str_mixed_data["key_$i"] = ($i & 1) === 0; break;
    }
}
$str_mix_keys = array_keys($str_mixed_data);

// Helper to bench and print a string-keyed type pair
function bench_str_type(string $id, string $label, int $jtype, array $data, array $keys, int $size, int $iterations, array $col): void {
    $j_pop = function() use ($jtype, $data) {
        $j = new Judy($jtype);
        foreach ($data as $k => $v) { $j[$k] = $v; }
        return $j;
    };
    $p_pop = function() use ($data) {
        $a = [];
        foreach ($data as $k => $v) { $a[$k] = $v; }
        return $a;
    };
    $j_ref = $j_pop(); $p_ref = $p_pop();

    $r = bench_core_type($id, $label, $jtype,
        $j_pop, $p_pop,
        function() use ($j_ref, $keys) { foreach ($keys as $k) { $v = $j_ref[$k]; } },
        function() use ($p_ref, $keys) { foreach ($keys as $k) { $v = $p_ref[$k]; } },
        $size, $iterations);
    printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
        $label,
        sprintf('%.2f', $r['write'][0]), sprintf('%.2f', $r['read'][0]),
        sprintf('%.2f', $r['iter'][0]),  sprintf('%.2f', $r['free'][0]),
        fmt_bytes($r['heap'][0]));
    printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %{$col[4]}s  %{$col[5]}s\n",
        '  PHP array',
        sprintf('%.2f', $r['write'][1]), sprintf('%.2f', $r['read'][1]),
        sprintf('%.2f', $r['iter'][1]),  sprintf('%.2f', $r['free'][1]),
        fmt_bytes($r['heap'][1]));
    unset($j_ref, $p_ref);
}

bench_str_type('core.string_to_int', 'STRING_TO_INT', Judy::STRING_TO_INT,
    $str_int_data, $str_keys, $size, $iterations, $col);
bench_str_type('core.string_to_mixed', 'STRING_TO_MIXED', Judy::STRING_TO_MIXED,
    $str_mixed_data, $str_mix_keys, $size, $iterations, $col);
if ($has_hash) {
    bench_str_type('core.string_to_int_hash', 'STR_TO_INT_HASH', Judy::STRING_TO_INT_HASH,
        $str_int_data, $str_keys, $size, $iterations, $col);
    bench_str_type('core.string_to_mixed_hash', 'STR_TO_MIX_HASH', Judy::STRING_TO_MIXED_HASH,
        $str_mixed_data, $str_mix_keys, $size, $iterations, $col);
}

if ($has_adaptive) {
    bench_str_type('core.string_to_int_adaptive', 'STR_INT_ADAPTIVE', Judy::STRING_TO_INT_ADAPTIVE,
        $str_int_data, $str_keys, $size, $iterations, $col);
    bench_str_type('core.string_to_mixed_adaptive', 'STR_MIX_ADAPTIVE', Judy::STRING_TO_MIXED_ADAPTIVE,
        $str_mixed_data, $str_mix_keys, $size, $iterations, $col);
}

unset($str_int_data, $str_keys, $str_mixed_data, $str_mix_keys);
echo "\n";

} // end core.str group

} // end core suite

// ╔═══════════════════════════════════════════════════════════════════════════╗
// ║ API SUITE                                                                ║
// ╚═══════════════════════════════════════════════════════════════════════════╝

if ($run_api) {

$col_api = [30, 12, 12, 10];

if (bench_group('api.batch')) {

echo "── API: Batch Operations (INT_TO_INT) ────────────────────────────────────────\n\n";

printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'Operation', 'Judy(ms)', 'PHP(ms)', 'Speedup');
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    str_repeat('─', $col_api[0]), str_repeat('─', $col_api[1]),
    str_repeat('─', $col_api[2]), str_repeat('─', $col_api[3]));

$int_data = [];
for ($i = 0; $i < $size; $i++) { $int_data[$i * 3] = $i * 7; }
$int_keys = array_keys($int_data);

// putAll
$t_judy = bench_median(function() use ($int_data) {
    $j = new Judy(Judy::INT_TO_INT);
    $j->putAll($int_data);
}, $iterations);
$t_php = bench_median(function() use ($int_data) {
    $j = new Judy(Judy::INT_TO_INT);
    foreach ($int_data as $k => $v) { $j[$k] = $v; }
}, $iterations);
record('api.putAll.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.putAll.int_to_int.php',  $t_php['median'],  $t_php['runs']);
$t_putall_php = $t_php; // save for fromArray comparison (same PHP-equivalent operation)
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'putAll()', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// fromArray
$t_judy = bench_median(function() use ($int_data) {
    Judy::fromArray(Judy::INT_TO_INT, $int_data);
}, $iterations);
record('api.fromArray.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.fromArray.int_to_int.php',  $t_putall_php['median'],  $t_putall_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'fromArray()', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_putall_php['median']), fmt_ratio($t_putall_php['median'], $t_judy['median']));

// toArray
$j_read = Judy::fromArray(Judy::INT_TO_INT, $int_data);
$t_judy = bench_median(function() use ($j_read) { $j_read->toArray(); }, $iterations);
$t_php = bench_median(function() use ($j_read) {
    $arr = []; foreach ($j_read as $k => $v) { $arr[$k] = $v; }
}, $iterations);
record('api.toArray.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.toArray.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'toArray()', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// getAll (10% of keys + 100 missing)
$get_count = max(1000, (int)($size * 0.1));
$get_keys = array_slice($int_keys, 0, $get_count);
for ($i = 0; $i < 100; $i++) $get_keys[] = $size * 10 + $i;

$t_judy = bench_median(function() use ($j_read, $get_keys) { $j_read->getAll($get_keys); }, $iterations);
$t_php = bench_median(function() use ($j_read, $get_keys) {
    $results = [];
    foreach ($get_keys as $k) { $results[$k] = $j_read[$k] ?? null; }
}, $iterations);
record('api.getAll.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.getAll.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'getAll(' . count($get_keys) . ' keys)', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// increment
$inc_unique = min(1000, $size);
$t_judy = bench_median(function() use ($size, $inc_unique) {
    $j = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $size; $i++) { $j->increment($i % $inc_unique); }
}, $iterations);
$t_php = bench_median(function() use ($size, $inc_unique) {
    $a = [];
    for ($i = 0; $i < $size; $i++) {
        $k = $i % $inc_unique;
        if (!isset($a[$k])) $a[$k] = 0;
        $a[$k]++;
    }
}, $iterations);
record('api.increment.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.increment.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    "increment($size ops/$inc_unique keys)", sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

unset($j_read, $int_data, $int_keys, $get_keys);
echo "\n";

// ── API: Native Methods ─────────────────────────────────────────────────────

echo "── API: Native Methods (keys, values, sum, popCount, deleteRange, equals) ────\n\n";

printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'Operation', 'Judy(ms)', 'PHP(ms)', 'Speedup');
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    str_repeat('─', $col_api[0]), str_repeat('─', $col_api[1]),
    str_repeat('─', $col_api[2]), str_repeat('─', $col_api[3]));

$j_int = new Judy(Judy::INT_TO_INT);
$j_str = new Judy(Judy::STRING_TO_INT);
for ($i = 0; $i < $size; $i++) {
    $j_int[$i] = $i * 3;
    $j_str["key_$i"] = $i * 3;
}

// keys() INT_TO_INT
$t_judy = bench_median(function() use ($j_int) { $j_int->keys(); }, $iterations);
$t_php = bench_median(function() use ($j_int) {
    $keys = []; foreach ($j_int as $k => $v) { $keys[] = $k; }
}, $iterations);
record('api.keys.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.keys.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'keys() INT_TO_INT', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// values() INT_TO_INT
$t_judy = bench_median(function() use ($j_int) { $j_int->values(); }, $iterations);
$t_php = bench_median(function() use ($j_int) {
    $vals = []; foreach ($j_int as $k => $v) { $vals[] = $v; }
}, $iterations);
record('api.values.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.values.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'values() INT_TO_INT', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// ── Bounded reads ───────────────────────────────────────────────────────────
// The docs make two performance claims about ranges that nothing measured
// until now: that keys($lo, $hi) beats slice($lo, $hi)->keys(), and that
// size($lo, $hi) beats count(keys($lo, $hi)). Both are the kind of claim that
// rots silently, so they are pinned here. The window is 10% of the key space
// in the middle, so a bound is genuinely cheaper than a full traversal.
$lo = (int)($size * 0.45);
$hi = (int)($size * 0.55);

// keys($lo, $hi) vs the slice() anti-pattern vs a PHP-array scan
$t_judy = bench_median(function() use ($j_int, $lo, $hi) { $j_int->keys($lo, $hi); }, $iterations);
$t_slice = bench_median(function() use ($j_int, $lo, $hi) { $j_int->slice($lo, $hi)->keys(); }, $iterations);
$t_php = bench_median(function() use ($j_int, $lo, $hi) {
    $keys = []; foreach ($j_int as $k => $v) { if ($k >= $lo && $k <= $hi) { $keys[] = $k; } }
}, $iterations);
record('api.range.keys.int_to_int.judy',  $t_judy['median'],  $t_judy['runs']);
record('api.range.keys.int_to_int.slice', $t_slice['median'], $t_slice['runs']);
record('api.range.keys.int_to_int.php',   $t_php['median'],   $t_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'keys($lo,$hi) INT_TO_INT', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    '  vs slice()->keys()', sprintf('%.2f', $t_slice['median']), '-',
    fmt_ratio($t_slice['median'], $t_judy['median']));

// size($lo, $hi) on integer keys is O(1) — libJudy answers it from the
// population cache without walking. A single call lands under timer
// resolution, which would record 0.000 and make every future comparison
// ratio meaningless, so it is measured over 1000 calls. Deliberately NOT
// compared against count(keys($lo, $hi)) here: that is O(range) by
// construction and can never win, so the ratio would be definitional rather
// than measured. The string pair below is the comparison that means something,
// because there both sides walk.
$t_judy = bench_median(function() use ($j_int, $lo, $hi) {
    for ($n = 0; $n < 1000; $n++) { $j_int->size($lo, $hi); }
}, $iterations);
record('api.range.size1000.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'size($lo,$hi) INT x1000', sprintf('%.2f', $t_judy['median']), '-', '     O(1)');

// The same pair on string keys, where 2.5.0 made size($lo, $hi) work at all
$s_lo = 'key_' . (int)($size * 0.45);
$s_hi = 'key_' . (int)($size * 0.55);
$t_judy = bench_median(function() use ($j_str, $s_lo, $s_hi) { $j_str->size($s_lo, $s_hi); }, $iterations);
$t_cnt = bench_median(function() use ($j_str, $s_lo, $s_hi) { count($j_str->keys($s_lo, $s_hi)); }, $iterations);
record('api.range.size.string_to_int.judy',      $t_judy['median'], $t_judy['runs']);
record('api.range.size.string_to_int.countkeys', $t_cnt['median'],  $t_cnt['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'size($lo,$hi) STRING_TO_INT', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_cnt['median']), fmt_ratio($t_cnt['median'], $t_judy['median']));

// sumValues() INT_TO_INT
$t_judy = bench_median(function() use ($j_int) { $j_int->sumValues(); }, $iterations);
$t_php = bench_median(function() use ($j_int) {
    $sum = 0; foreach ($j_int as $v) { $sum += $v; }
}, $iterations);
record('api.sumValues.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.sumValues.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'sumValues() INT_TO_INT', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// populationCount() INT_TO_INT (full range)
$t_judy = bench_median(function() use ($j_int) { $j_int->populationCount(); }, $iterations);
$t_php = bench_median(function() use ($j_int) {
    $count = 0; foreach ($j_int as $k => $v) { $count++; }
}, $iterations);
record('api.populationCount.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.populationCount.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'populationCount() INT_TO_INT', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// deleteRange() INT_TO_INT (middle 50%)
$del_start = (int)($size * 0.25);
$del_end   = (int)($size * 0.75);
$t_judy = bench_median(function() use ($size, $del_start, $del_end) {
    $j = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $size; $i++) { $j[$i] = $i * 3; }
    $j->deleteRange($del_start, $del_end);
}, $iterations);
$t_php = bench_median(function() use ($size, $del_start, $del_end) {
    $j = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $size; $i++) { $j[$i] = $i * 3; }
    foreach ($j as $k => $v) {
        if ($k >= $del_start && $k <= $del_end) unset($j[$k]);
        if ($k > $del_end) break;
    }
}, $iterations);
record('api.deleteRange.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.deleteRange.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'deleteRange() INT_TO_INT mid50%', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// equals() INT_TO_INT
$j_int_b = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < $size; $i++) { $j_int_b[$i] = $i * 3; }

$t_judy = bench_median(function() use ($j_int, $j_int_b) { $j_int->equals($j_int_b); }, $iterations);
$t_php = bench_median(function() use ($j_int, $j_int_b) {
    $equal = (count($j_int) === count($j_int_b));
    if ($equal) {
        foreach ($j_int as $k => $v) {
            if (!isset($j_int_b[$k]) || $j_int_b[$k] !== $v) { $equal = false; break; }
        }
    }
}, $iterations);
record('api.equals.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('api.equals.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'equals() INT_TO_INT', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

unset($j_int, $j_str, $j_int_b);
echo "\n";

} // end api.batch group

// ── API: Set Operations ─────────────────────────────────────────────────────

if (bench_group('api.setops')) {

echo "── API: Set Operations (BITSET, 50% overlap) ────────────────────────────────\n\n";

printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    'Operation', 'Judy(ms)', 'PHP(ms)', 'Speedup');
printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
    str_repeat('─', $col_api[0]), str_repeat('─', $col_api[1]),
    str_repeat('─', $col_api[2]), str_repeat('─', $col_api[3]));

$overlap = (int)($size * 0.5);
$only_a = $size - $overlap;

$judy_a = new Judy(Judy::BITSET);
$judy_b = new Judy(Judy::BITSET);
$php_a = []; $php_b = [];
for ($i = 0; $i < $size; $i++) {
    $judy_a[$i] = true;
    $judy_b[$only_a + $i] = true;
    $php_a[$i] = true;
    $php_b[$only_a + $i] = true;
}

foreach (['union', 'intersect', 'diff', 'xor'] as $op) {
    $t_judy = bench_median(function() use ($judy_a, $judy_b, $op) {
        $judy_a->$op($judy_b);
    }, $iterations);

    switch ($op) {
        case 'union':
            $t_php = bench_median(function() use ($php_a, $php_b) {
                array_replace($php_a, $php_b);
            }, $iterations);
            break;
        case 'intersect':
            $t_php = bench_median(function() use ($php_a, $php_b) {
                array_intersect_key($php_a, $php_b);
            }, $iterations);
            break;
        case 'diff':
            $t_php = bench_median(function() use ($php_a, $php_b) {
                array_diff_key($php_a, $php_b);
            }, $iterations);
            break;
        case 'xor':
            $t_php = bench_median(function() use ($php_a, $php_b) {
                array_replace(array_diff_key($php_a, $php_b), array_diff_key($php_b, $php_a));
            }, $iterations);
            break;
    }

    record("api.setop.$op.bitset.judy", $t_judy['median'], $t_judy['runs']);
    record("api.setop.$op.bitset.php",  $t_php['median'],  $t_php['runs']);
    printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
        "$op() BITSET", sprintf('%.2f', $t_judy['median']),
        sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));
}

// INT_TO_INT set ops
$j_a = new Judy(Judy::INT_TO_INT);
$j_b = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < $size; $i++) {
    $j_a[$i] = $i;
    $j_b[$only_a + $i] = $i + $size;
}

foreach (['union', 'intersect', 'diff', 'xor'] as $op) {
    $t_judy = bench_median(function() use ($j_a, $j_b, $op) {
        $j_a->$op($j_b);
    }, $iterations);

    // PHP equivalent: manual foreach
    $t_php = bench_median(function() use ($j_a, $j_b, $op) {
        $result = new Judy(Judy::INT_TO_INT);
        switch ($op) {
            case 'union':
                foreach ($j_b as $k => $v) { $result[$k] = $v; }
                foreach ($j_a as $k => $v) { $result[$k] = $v; }
                break;
            case 'intersect':
                foreach ($j_a as $k => $v) { if (isset($j_b[$k])) $result[$k] = $v; }
                break;
            case 'diff':
                foreach ($j_a as $k => $v) { if (!isset($j_b[$k])) $result[$k] = $v; }
                break;
            case 'xor':
                foreach ($j_a as $k => $v) { if (!isset($j_b[$k])) $result[$k] = $v; }
                foreach ($j_b as $k => $v) { if (!isset($j_a[$k])) $result[$k] = $v; }
                break;
        }
    }, $iterations);

    record("api.setop.$op.int_to_int.judy", $t_judy['median'], $t_judy['runs']);
    record("api.setop.$op.int_to_int.php",  $t_php['median'],  $t_php['runs']);
    printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
        "$op() INT_TO_INT", sprintf('%.2f', $t_judy['median']),
        sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));
}

// STRING_TO_INT set ops
$js_a = new Judy(Judy::STRING_TO_INT);
$js_b = new Judy(Judy::STRING_TO_INT);
$half = (int)($size / 2);
for ($i = 0; $i < $size; $i++) {
    $js_a["k_$i"] = $i;
    $js_b["k_" . ($i + $half)] = $i + $size;
}

foreach (['union', 'intersect', 'diff'] as $op) {
    $t_judy = bench_median(function() use ($js_a, $js_b, $op) {
        $js_a->$op($js_b);
    }, $iterations);

    $t_php = bench_median(function() use ($js_a, $js_b, $op) {
        $result = new Judy(Judy::STRING_TO_INT);
        switch ($op) {
            case 'union':
                foreach ($js_b as $k => $v) { $result[$k] = $v; }
                foreach ($js_a as $k => $v) { $result[$k] = $v; }
                break;
            case 'intersect':
                foreach ($js_a as $k => $v) { if (isset($js_b[$k])) $result[$k] = $v; }
                break;
            case 'diff':
                foreach ($js_a as $k => $v) { if (!isset($js_b[$k])) $result[$k] = $v; }
                break;
        }
    }, $iterations);

    record("api.setop.$op.string_to_int.judy", $t_judy['median'], $t_judy['runs']);
    record("api.setop.$op.string_to_int.php",  $t_php['median'],  $t_php['runs']);
    printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
        "$op() STRING_TO_INT", sprintf('%.2f', $t_judy['median']),
        sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));
}

// mergeWith() — in-place merge (INT_TO_INT)
{
    $mw_a = new Judy(Judy::INT_TO_INT);
    $mw_b = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $size; $i++) {
        $mw_a[$i] = $i;
        $mw_b[$only_a + $i] = $i + $size;
    }

    $t_judy = bench_median(function() use ($mw_a, $mw_b, $size, $only_a) {
        $target = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) { $target[$i] = $i; }
        $target->mergeWith($mw_b);
    }, $iterations);

    $t_php = bench_median(function() use ($mw_a, $mw_b, $size, $only_a) {
        $target = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) { $target[$i] = $i; }
        foreach ($mw_b as $k => $v) { $target[$k] = $v; }
    }, $iterations);

    record("api.mergeWith.int_to_int.judy", $t_judy['median'], $t_judy['runs']);
    record("api.mergeWith.int_to_int.php",  $t_php['median'],  $t_php['runs']);
    printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
        'mergeWith() INT_TO_INT', sprintf('%.2f', $t_judy['median']),
        sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

    unset($mw_a, $mw_b);
}

// mergeWith() — in-place merge (STRING_TO_INT)
{
    $mws_a = new Judy(Judy::STRING_TO_INT);
    $mws_b = new Judy(Judy::STRING_TO_INT);
    for ($i = 0; $i < $size; $i++) {
        $mws_a["k_$i"] = $i;
        $mws_b["k_" . ($i + $half)] = $i + $size;
    }

    $t_judy = bench_median(function() use ($mws_a, $mws_b, $size) {
        $target = new Judy(Judy::STRING_TO_INT);
        for ($i = 0; $i < $size; $i++) { $target["k_$i"] = $i; }
        $target->mergeWith($mws_b);
    }, $iterations);

    $t_php = bench_median(function() use ($mws_a, $mws_b, $size) {
        $target = new Judy(Judy::STRING_TO_INT);
        for ($i = 0; $i < $size; $i++) { $target["k_$i"] = $i; }
        foreach ($mws_b as $k => $v) { $target[$k] = $v; }
    }, $iterations);

    record("api.mergeWith.string_to_int.judy", $t_judy['median'], $t_judy['runs']);
    record("api.mergeWith.string_to_int.php",  $t_php['median'],  $t_php['runs']);
    printf("  %-{$col_api[0]}s  %{$col_api[1]}s  %{$col_api[2]}s  %{$col_api[3]}s\n",
        'mergeWith() STRING_TO_INT', sprintf('%.2f', $t_judy['median']),
        sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

    unset($mws_a, $mws_b);
}

unset($judy_a, $judy_b, $php_a, $php_b, $j_a, $j_b, $js_a, $js_b);
echo "\n";

} // end api.setops group

} // end api suite

// ╔═══════════════════════════════════════════════════════════════════════════╗
// ║ ADVANCED SUITE                                                           ║
// ╚═══════════════════════════════════════════════════════════════════════════╝

if ($run_advanced) {

$col_adv = [30, 12, 12, 10];

// ── Advanced: C-level forEach/filter/map ────────────────────────────────────

if (bench_group('adv.iter')) {

echo "── Advanced: C-Level forEach/filter/map ──────────────────────────────────────\n\n";

printf("  %-{$col_adv[0]}s  %{$col_adv[1]}s  %{$col_adv[2]}s  %{$col_adv[3]}s\n",
    'Operation', 'C-level(ms)', 'PHP(ms)', 'Speedup');
printf("  %-{$col_adv[0]}s  %{$col_adv[1]}s  %{$col_adv[2]}s  %{$col_adv[3]}s\n",
    str_repeat('─', $col_adv[0]), str_repeat('─', $col_adv[1]),
    str_repeat('─', $col_adv[2]), str_repeat('─', $col_adv[3]));

$j_int = new Judy(Judy::INT_TO_INT);
$j_str = new Judy(Judy::STRING_TO_INT);
for ($i = 0; $i < $size; $i++) {
    $j_int[$i] = $i * 3;
    $j_str["key_$i"] = $i * 3;
}

// forEach INT_TO_INT
$t_judy = bench_median(function() use ($j_int) {
    $sum = 0;
    $j_int->forEach(function($v, $k) use (&$sum) { $sum += $v; });
}, $iterations);
$t_php = bench_median(function() use ($j_int) {
    $sum = 0; foreach ($j_int as $k => $v) { $sum += $v; }
}, $iterations);
record('adv.forEach.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('adv.forEach.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_adv[0]}s  %{$col_adv[1]}s  %{$col_adv[2]}s  %{$col_adv[3]}s\n",
    'forEach() INT_TO_INT', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// forEach STRING_TO_INT
$t_judy = bench_median(function() use ($j_str) {
    $sum = 0;
    $j_str->forEach(function($v, $k) use (&$sum) { $sum += $v; });
}, $iterations);
$t_php = bench_median(function() use ($j_str) {
    $sum = 0; foreach ($j_str as $k => $v) { $sum += $v; }
}, $iterations);
record('adv.forEach.string_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('adv.forEach.string_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_adv[0]}s  %{$col_adv[1]}s  %{$col_adv[2]}s  %{$col_adv[3]}s\n",
    'forEach() STRING_TO_INT', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// filter INT_TO_INT (keep even values)
$t_judy = bench_median(function() use ($j_int) {
    $j_int->filter(function($v) { return $v % 2 === 0; });
}, $iterations);
$t_php = bench_median(function() use ($j_int) {
    $result = new Judy(Judy::INT_TO_INT);
    foreach ($j_int as $k => $v) { if ($v % 2 === 0) $result[$k] = $v; }
}, $iterations);
record('adv.filter.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('adv.filter.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_adv[0]}s  %{$col_adv[1]}s  %{$col_adv[2]}s  %{$col_adv[3]}s\n",
    'filter() INT_TO_INT (50% pass)', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// filter STRING_TO_INT
$t_judy = bench_median(function() use ($j_str) {
    $j_str->filter(function($v) { return $v % 2 === 0; });
}, $iterations);
$t_php = bench_median(function() use ($j_str) {
    $result = new Judy(Judy::STRING_TO_INT);
    foreach ($j_str as $k => $v) { if ($v % 2 === 0) $result[$k] = $v; }
}, $iterations);
record('adv.filter.string_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('adv.filter.string_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_adv[0]}s  %{$col_adv[1]}s  %{$col_adv[2]}s  %{$col_adv[3]}s\n",
    'filter() STRING_TO_INT (50% pass)', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// map INT_TO_INT (multiply by 2)
$t_judy = bench_median(function() use ($j_int) {
    $j_int->map(function($v) { return $v * 2; });
}, $iterations);
$t_php = bench_median(function() use ($j_int) {
    $result = new Judy(Judy::INT_TO_INT);
    foreach ($j_int as $k => $v) { $result[$k] = $v * 2; }
}, $iterations);
record('adv.map.int_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('adv.map.int_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_adv[0]}s  %{$col_adv[1]}s  %{$col_adv[2]}s  %{$col_adv[3]}s\n",
    'map() INT_TO_INT (*2)', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

// map STRING_TO_INT
$t_judy = bench_median(function() use ($j_str) {
    $j_str->map(function($v) { return $v * 2; });
}, $iterations);
$t_php = bench_median(function() use ($j_str) {
    $result = new Judy(Judy::STRING_TO_INT);
    foreach ($j_str as $k => $v) { $result[$k] = $v * 2; }
}, $iterations);
record('adv.map.string_to_int.judy', $t_judy['median'], $t_judy['runs']);
record('adv.map.string_to_int.php',  $t_php['median'],  $t_php['runs']);
printf("  %-{$col_adv[0]}s  %{$col_adv[1]}s  %{$col_adv[2]}s  %{$col_adv[3]}s\n",
    'map() STRING_TO_INT (*2)', sprintf('%.2f', $t_judy['median']),
    sprintf('%.2f', $t_php['median']), fmt_ratio($t_php['median'], $t_judy['median']));

unset($j_int, $j_str);
echo "\n";

// ── Advanced: the optimizeIteration trade ───────────────────────────────────
//
// Skipped wholesale on a build without the opt-in, so the release-comparison
// baseline arm runs this group to completion and simply emits its usual id set
// with these entries absent. Nothing here may construct an optimized instance
// before this gate.

if ($has_optiter && $has_hash && $has_adaptive) {

echo "── Advanced: optimizeIteration trade (opt-in key-index payload mirror) ──────\n\n";

// Capped independently of --size. This section runs twenty timed benchmarks
// and the headline CI invocation passes --size 500000, where the full element
// count would cost more wall clock than the trade is worth measuring at. What
// this section reports is a ratio, a property of the storage rather than of n.
$n_oi = min($size, 200000);

/** Distinct keys of exactly $len bytes (fixed-width index, then padded). */
$optiter_keys = static function (int $n, int $len): array {
    $keys = [];
    for ($i = 0; $i < $n; $i++) {
        $keys[] = str_pad('oi_' . str_pad((string)$i, 9, '0', STR_PAD_LEFT), $len, '#');
    }
    return $keys;
};

// The two key lengths #100 measured, which are also the two ends of the trade:
// the read win grows with key length while the write penalty shrinks. Only
// STRING_TO_INT_HASH and STRING_TO_INT_ADAPTIVE can honour the flag, and
// ADAPTIVE only at 8 bytes or more — both lengths here clear that.
$oi_sets = [
    ['id' => 'string_to_int_hash',     'label' => 'HASH kl16',
     'type' => Judy::STRING_TO_INT_HASH,     'keylen' => 16],
    ['id' => 'string_to_int_adaptive', 'label' => 'ADAPTIVE kl40',
     'type' => Judy::STRING_TO_INT_ADAPTIVE, 'keylen' => 40],
];

$col_oi = [30, 12, 12, 12];
printf("  %-{$col_oi[0]}s  %{$col_oi[1]}s  %{$col_oi[2]}s  %{$col_oi[3]}s\n",
    'Operation', 'default(ms)', 'optim.(ms)', 'optim./def.');
printf("  %-{$col_oi[0]}s  %{$col_oi[1]}s  %{$col_oi[2]}s  %{$col_oi[3]}s\n",
    str_repeat('─', $col_oi[0]), str_repeat('─', $col_oi[1]),
    str_repeat('─', $col_oi[2]), str_repeat('─', $col_oi[3]));

$oi_skipped = [];

foreach ($oi_sets as $set) {
    $keys = $optiter_keys($n_oi, $set['keylen']);

    $oi_def = new Judy($set['type']);
    $oi_opt = new Judy($set['type'], optimizeIteration: true);
    foreach ($keys as $i => $k) {
        $oi_def[$k] = $i;
        $oi_opt[$k] = $i;
    }

    // Reported, not gated on. A build that stops honouring the request for a
    // type it used to honour has lost the win just as surely as one whose
    // mirror got slower, and the ratio below climbs to ~100 in both cases —
    // which is what should be flagged. Skipping here would hide that instead.
    $honoured = $oi_opt->isIterationOptimized() && !$oi_def->isIterationOptimized();

    // Ordered traversal — the side the trade buys.
    $ops = [
        // Keyed foreach on purpose: it is the shape #100 measured, and the
        // unkeyed form does not ask the iterator for a key at all.
        'foreach' => [
            static function () use ($oi_def) { $s = 0; foreach ($oi_def as $k => $v) { $s += $v; } },
            static function () use ($oi_opt) { $s = 0; foreach ($oi_opt as $k => $v) { $s += $v; } },
        ],
        'values' => [
            static function () use ($oi_def) { $oi_def->values(); },
            static function () use ($oi_opt) { $oi_opt->values(); },
        ],
        'toArray' => [
            static function () use ($oi_def) { $oi_def->toArray(); },
            static function () use ($oi_opt) { $oi_opt->toArray(); },
        ],
        // Write path — the side the trade pays with. Both run against the
        // already-populated instance, so they measure the overwrite path
        // rather than insertion, which is where the mirror's second update
        // shows up.
        'overwrite' => [
            static function () use ($oi_def, $keys) { foreach ($keys as $i => $k) { $oi_def[$k] = $i; } },
            static function () use ($oi_opt, $keys) { foreach ($keys as $i => $k) { $oi_opt[$k] = $i; } },
        ],
    ];

    // increment() is not defined for every type that honours the opt-in —
    // STRING_TO_INT_ADAPTIVE throws for it. Probe with one call rather than
    // hard-coding the list, so this section keeps working if the supported
    // set changes.
    try {
        $oi_def->increment($keys[0]);
        $oi_opt->increment($keys[0]);
        $ops['increment'] = [
            static function () use ($oi_def, $keys) { foreach ($keys as $k) { $oi_def->increment($k); } },
            static function () use ($oi_opt, $keys) { foreach ($keys as $k) { $oi_opt->increment($k); } },
        ];
    } catch (\Throwable $e) {
        // Left out of the results entirely: no id, no zero-valued entry.
        $oi_skipped[] = "increment() {$set['label']}: " . $e->getMessage();
    }

    foreach ($ops as $op => $fns) {
        $t_def = bench_median($fns[0], $iterations);
        $t_opt = bench_median($fns[1], $iterations);

        record("adv.optiter.$op.{$set['id']}.default.judy",   $t_def['median'], $t_def['runs']);
        record("adv.optiter.$op.{$set['id']}.optimized.judy", $t_opt['median'], $t_opt['runs']);
        record_optiter_ratio($op, $set['id'], $t_def['median'], $t_opt['median']);

        printf("  %-{$col_oi[0]}s  %{$col_oi[1]}s  %{$col_oi[2]}s  %{$col_oi[3]}s\n",
            "$op() {$set['label']}",
            sprintf('%.2f', $t_def['median']), sprintf('%.2f', $t_opt['median']),
            $t_def['median'] > 0
                ? sprintf('%.1f%%', $t_opt['median'] / $t_def['median'] * 100.0)
                : 'n/a');
    }
    printf("  %-{$col_oi[0]}s  %s\n", "  {$set['label']}: honoured?",
        $honoured ? 'yes' : 'NO — the opt-in did not take effect');

    unset($oi_def, $oi_opt, $keys, $ops);
}

foreach ($oi_skipped as $why) {
    echo "  skipped — $why\n";
}

echo "\n  Ratios are optimized as a percentage of default, measured inside this\n";
echo "  build; below 100 is the traversal win, above 100 the write price. Lower\n";
echo "  is better on every row, so a lost win reads as a rise.\n\n";

} // end optimizeIteration section

} // end adv.iter group

// ── Advanced: Adaptive SSO comparison ───────────────────────────────────────

if ($has_adaptive && bench_group('adv.sso')) {

echo "── Advanced: Adaptive SSO — short keys (<8 bytes) via JudyL ─────────────────\n\n";

$col_sso = [22, 14, 14, 14, 12];
printf("  %-{$col_sso[0]}s  %{$col_sso[1]}s  %{$col_sso[2]}s  %{$col_sso[3]}s  %{$col_sso[4]}s\n",
    'Operation', 'STR_TO_INT', 'STR_INT_HASH', 'STR_INT_ADPTV', 'Best ratio');
printf("  %-{$col_sso[0]}s  %{$col_sso[1]}s  %{$col_sso[2]}s  %{$col_sso[3]}s  %{$col_sso[4]}s\n",
    str_repeat('─', $col_sso[0]), str_repeat('─', $col_sso[1]),
    str_repeat('─', $col_sso[2]), str_repeat('─', $col_sso[3]), str_repeat('─', $col_sso[4]));

$short_keys = [];
for ($i = 0; $i < $size; $i++) {
    $short_keys[] = base_convert($i, 10, 36);
}

// Insert short keys
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

record('adv.sso.insert_short.string_to_int',          $t_sl['median'], $t_sl['runs']);
record('adv.sso.insert_short.string_to_int_hash',     $t_hs['median'], $t_hs['runs']);
record('adv.sso.insert_short.string_to_int_adaptive', $t_ad['median'], $t_ad['runs']);

$best = min($t_sl['median'], $t_hs['median'], $t_ad['median']);
printf("  %-{$col_sso[0]}s  %s  %s  %s  %s\n",
    'insert (short keys)', fmt_ms($t_sl['median']), fmt_ms($t_hs['median']),
    fmt_ms($t_ad['median']), fmt_ratio(max($t_sl['median'], $t_hs['median']), $best));

// Read short keys
$j_sl = new Judy(Judy::STRING_TO_INT);
$j_hs = new Judy(Judy::STRING_TO_INT_HASH);
$j_ad = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
for ($i = 0; $i < $size; $i++) {
    $j_sl[$short_keys[$i]] = $i;
    $j_hs[$short_keys[$i]] = $i;
    $j_ad[$short_keys[$i]] = $i;
}

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

record('adv.sso.read_short.string_to_int',          $t_sl['median'], $t_sl['runs']);
record('adv.sso.read_short.string_to_int_hash',     $t_hs['median'], $t_hs['runs']);
record('adv.sso.read_short.string_to_int_adaptive', $t_ad['median'], $t_ad['runs']);

$best = min($t_sl['median'], $t_hs['median'], $t_ad['median']);
printf("  %-{$col_sso[0]}s  %s  %s  %s  %s\n",
    'read (random access)', fmt_ms($t_sl['median']), fmt_ms($t_hs['median']),
    fmt_ms($t_ad['median']), fmt_ratio(max($t_sl['median'], $t_hs['median']), $best));

// Iterate short keys
$t_sl = bench_median(function() use ($j_sl) {
    $sum = 0; foreach ($j_sl as $k => $v) { $sum += $v; }
}, $iterations);
$t_hs = bench_median(function() use ($j_hs) {
    $sum = 0; foreach ($j_hs as $k => $v) { $sum += $v; }
}, $iterations);
$t_ad = bench_median(function() use ($j_ad) {
    $sum = 0; foreach ($j_ad as $k => $v) { $sum += $v; }
}, $iterations);

record('adv.sso.iter_short.string_to_int',          $t_sl['median'], $t_sl['runs']);
record('adv.sso.iter_short.string_to_int_hash',     $t_hs['median'], $t_hs['runs']);
record('adv.sso.iter_short.string_to_int_adaptive', $t_ad['median'], $t_ad['runs']);

$best = min($t_sl['median'], $t_hs['median'], $t_ad['median']);
printf("  %-{$col_sso[0]}s  %s  %s  %s  %s\n",
    'foreach (iterate all)', fmt_ms($t_sl['median']), fmt_ms($t_hs['median']),
    fmt_ms($t_ad['median']), fmt_ratio(max($t_sl['median'], $t_hs['median']), $best));

unset($j_sl, $j_hs, $j_ad, $short_keys, $read_keys, $read_subset);
echo "\n";

// ── Advanced: Adaptive SSO — long keys ──────────────────────────────────────

echo "── Advanced: Adaptive SSO — long keys (>=8 bytes) fallback to JudyHS ────────\n\n";

printf("  %-{$col_sso[0]}s  %{$col_sso[1]}s  %{$col_sso[2]}s  %{$col_sso[3]}s  %{$col_sso[4]}s\n",
    'Operation', 'STR_TO_INT', 'STR_INT_HASH', 'STR_INT_ADPTV', 'Best ratio');
printf("  %-{$col_sso[0]}s  %{$col_sso[1]}s  %{$col_sso[2]}s  %{$col_sso[3]}s  %{$col_sso[4]}s\n",
    str_repeat('─', $col_sso[0]), str_repeat('─', $col_sso[1]),
    str_repeat('─', $col_sso[2]), str_repeat('─', $col_sso[3]), str_repeat('─', $col_sso[4]));

$long_keys = [];
for ($i = 0; $i < $size; $i++) {
    $long_keys[] = "long_key_prefix_" . str_pad($i, 8, '0', STR_PAD_LEFT);
}

// Insert long keys
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

record('adv.sso.insert_long.string_to_int',          $t_sl['median'], $t_sl['runs']);
record('adv.sso.insert_long.string_to_int_hash',     $t_hs['median'], $t_hs['runs']);
record('adv.sso.insert_long.string_to_int_adaptive', $t_ad['median'], $t_ad['runs']);

$best = min($t_sl['median'], $t_hs['median'], $t_ad['median']);
printf("  %-{$col_sso[0]}s  %s  %s  %s  %s\n",
    'insert (long keys)', fmt_ms($t_sl['median']), fmt_ms($t_hs['median']),
    fmt_ms($t_ad['median']), fmt_ratio(max($t_sl['median'], $t_hs['median']), $best));

// Pre-populate for read
$jl_sl = new Judy(Judy::STRING_TO_INT);
$jl_hs = new Judy(Judy::STRING_TO_INT_HASH);
$jl_ad = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
for ($i = 0; $i < $size; $i++) {
    $jl_sl[$long_keys[$i]] = $i;
    $jl_hs[$long_keys[$i]] = $i;
    $jl_ad[$long_keys[$i]] = $i;
}

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

record('adv.sso.read_long.string_to_int',          $t_sl['median'], $t_sl['runs']);
record('adv.sso.read_long.string_to_int_hash',     $t_hs['median'], $t_hs['runs']);
record('adv.sso.read_long.string_to_int_adaptive', $t_ad['median'], $t_ad['runs']);

$best = min($t_sl['median'], $t_hs['median'], $t_ad['median']);
printf("  %-{$col_sso[0]}s  %s  %s  %s  %s\n",
    'read (random access)', fmt_ms($t_sl['median']), fmt_ms($t_hs['median']),
    fmt_ms($t_ad['median']), fmt_ratio(max($t_sl['median'], $t_hs['median']), $best));

// Iterate long keys
$t_sl = bench_median(function() use ($jl_sl) {
    $sum = 0; foreach ($jl_sl as $k => $v) { $sum += $v; }
}, $iterations);
$t_hs = bench_median(function() use ($jl_hs) {
    $sum = 0; foreach ($jl_hs as $k => $v) { $sum += $v; }
}, $iterations);
$t_ad = bench_median(function() use ($jl_ad) {
    $sum = 0; foreach ($jl_ad as $k => $v) { $sum += $v; }
}, $iterations);

record('adv.sso.iter_long.string_to_int',          $t_sl['median'], $t_sl['runs']);
record('adv.sso.iter_long.string_to_int_hash',     $t_hs['median'], $t_hs['runs']);
record('adv.sso.iter_long.string_to_int_adaptive', $t_ad['median'], $t_ad['runs']);

$best = min($t_sl['median'], $t_hs['median'], $t_ad['median']);
printf("  %-{$col_sso[0]}s  %s  %s  %s  %s\n",
    'foreach (iterate all)', fmt_ms($t_sl['median']), fmt_ms($t_hs['median']),
    fmt_ms($t_ad['median']), fmt_ratio(max($t_sl['median'], $t_hs['median']), $best));

unset($jl_sl, $jl_hs, $jl_ad, $long_keys, $lr_keys, $lr_subset);
echo "\n";

} // end has_adaptive

} // end advanced suite

// ── Footer ──────────────────────────────────────────────────────────────────

echo "$div\n";
echo "  Benchmark complete — " . date('Y-m-d H:i:s') . "\n";
echo "  All timings: median of $iterations iterations via hrtime(true), 1 warmup run\n";
echo "$div\n";

// ── JSON output ─────────────────────────────────────────────────────────────

if ($json_file !== null) {
    $json = json_encode($json_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($json_file, $json . "\n") !== false) {
        echo "\n  JSON results written to: $json_file\n";
    } else {
        fwrite(STDERR, "ERROR: Could not write JSON to $json_file\n");
        exit(1);
    }
}
