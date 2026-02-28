<?php
/**
 * Memory Benchmark: Judy Types — Valgrind Massif
 *
 * Measures C-level malloc overhead for each Judy array type using Valgrind
 * Massif. Each type is tested in an isolated sub-process so peak heap
 * measurements are per-type.
 *
 * This specifically measures libJudy.so's malloc allocations — the metric
 * that is missing for STRING_TO_* types where memoryUsage() returns null.
 * PHP arrays use emalloc (mmap'd) and won't appear here; see the 'Heap delta'
 * column in judy-bench-all-types.php for PHP-level memory comparison.
 *
 * Designed to run inside the Docker container where both valgrind and the
 * judy PHP extension are available.
 *
 * Usage:
 *   docker build -t php-judy-test .
 *   docker run --rm php-judy-test php examples/judy-bench-memory.php [size]
 *   docker run --rm php-judy-test php examples/judy-bench-memory.php 100000
 *
 * Default size: 100000 (Valgrind adds ~10-20x overhead, keep moderate).
 */

$size = isset($argv[1]) ? (int)$argv[1] : 100000;

// ── Verify environment ──────────────────────────────────────────────────

$valgrind = trim(shell_exec('which valgrind 2>/dev/null') ?? '');
if ($valgrind === '') {
    fwrite(STDERR, "Error: valgrind not found. Run this inside the Docker container.\n");
    exit(1);
}

if (!extension_loaded('judy')) {
    fwrite(STDERR, "Error: judy extension not loaded.\n");
    exit(1);
}

// ── Detect available types ──────────────────────────────────────────────

$has_packed = false;
try {
    $tmp = new Judy(Judy::INT_TO_PACKED);
    unset($tmp);
    $has_packed = true;
} catch (Exception $e) {}

// ── Type definitions ────────────────────────────────────────────────────

// Each entry: label, PHP code to fill the container with $N elements.
// The code must define $N from the environment and allocate exactly one
// container that stays alive until process exit.
$types = [
    'PHP array (int→int)' => <<<'PHP'
        $a = []; for ($i = 0; $i < $N; $i++) $a[$i] = $i;
PHP,
    'BITSET' => <<<'PHP'
        $j = new Judy(Judy::BITSET);
        for ($i = 0; $i < $N; $i++) $j[$i] = true;
PHP,
    'INT_TO_INT' => <<<'PHP'
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $N; $i++) $j[$i] = $i;
PHP,
    'INT_TO_MIXED' => <<<'PHP'
        $j = new Judy(Judy::INT_TO_MIXED);
        for ($i = 0; $i < $N; $i++) {
            switch ($i & 3) {
                case 0: $j[$i] = "str_$i"; break;
                case 1: $j[$i] = $i * 7; break;
                case 2: $j[$i] = [$i, $i+1]; break;
                case 3: $j[$i] = ($i & 1) === 0; break;
            }
        }
PHP,
    'STRING_TO_INT' => <<<'PHP'
        $j = new Judy(Judy::STRING_TO_INT);
        for ($i = 0; $i < $N; $i++) $j["key_$i"] = $i;
PHP,
    'STRING_TO_MIXED' => <<<'PHP'
        $j = new Judy(Judy::STRING_TO_MIXED);
        for ($i = 0; $i < $N; $i++) {
            switch ($i & 3) {
                case 0: $j["key_$i"] = "str_$i"; break;
                case 1: $j["key_$i"] = $i * 7; break;
                case 2: $j["key_$i"] = [$i, $i+1]; break;
                case 3: $j["key_$i"] = ($i & 1) === 0; break;
            }
        }
PHP,
    'STRING_TO_MIXED_HASH' => <<<'PHP'
        $j = new Judy(Judy::STRING_TO_MIXED_HASH);
        for ($i = 0; $i < $N; $i++) {
            switch ($i & 3) {
                case 0: $j["key_$i"] = "str_$i"; break;
                case 1: $j["key_$i"] = $i * 7; break;
                case 2: $j["key_$i"] = [$i, $i+1]; break;
                case 3: $j["key_$i"] = ($i & 1) === 0; break;
            }
        }
PHP,
];

if ($has_packed) {
    // Insert INT_TO_PACKED after INT_TO_MIXED
    $before = array_slice($types, 0, 4, true);
    $after  = array_slice($types, 4, null, true);
    $types = $before + [
        'INT_TO_PACKED' => <<<'PHP'
            $j = new Judy(Judy::INT_TO_PACKED);
            for ($i = 0; $i < $N; $i++) {
                switch ($i & 3) {
                    case 0: $j[$i] = "str_$i"; break;
                    case 1: $j[$i] = $i * 7; break;
                    case 2: $j[$i] = [$i, $i+1]; break;
                    case 3: $j[$i] = ($i & 1) === 0; break;
                }
            }
PHP,
    ] + $after;
}

// ── Helper: parse Massif output file ────────────────────────────────────

/**
 * Parse a Massif output file and return the peak heap bytes.
 * Looks for the snapshot with heap_tree=peak, or falls back to max mem_heap_B.
 */
function parse_massif_peak(string $file): ?int {
    if (!file_exists($file)) return null;
    $content = file_get_contents($file);
    if ($content === false) return null;

    // Split into snapshots
    $snapshots = preg_split('/^#-+$/m', $content);
    $peak_bytes = 0;

    foreach ($snapshots as $snapshot) {
        // Look for heap_tree=peak
        if (preg_match('/heap_tree=peak/', $snapshot)) {
            if (preg_match('/mem_heap_B=(\d+)/', $snapshot, $m)) {
                return (int)$m[1];
            }
        }
        // Track max as fallback
        if (preg_match('/mem_heap_B=(\d+)/', $snapshot, $m)) {
            $peak_bytes = max($peak_bytes, (int)$m[1]);
        }
    }

    return $peak_bytes > 0 ? $peak_bytes : null;
}

/**
 * Run a PHP snippet under Valgrind Massif and return peak heap bytes.
 */
function measure_massif(string $label, string $php_code, int $size): ?int {
    $outfile = tempnam('/tmp', 'massif_');

    // Build the one-liner: define $N, run the fill code, then sleep(0) to
    // give Massif a clean final snapshot.
    $script = sprintf('$N = %d; %s', $size, trim($php_code));

    $cmd = sprintf(
        'valgrind --tool=massif --massif-out-file=%s '
        . 'php -r %s 2>/dev/null',
        escapeshellarg($outfile),
        escapeshellarg($script)
    );

    exec($cmd, $output, $rc);

    $peak = parse_massif_peak($outfile);
    @unlink($outfile);

    return $peak;
}

// ── Measure baseline (empty PHP process) ────────────────────────────────

fprintf(STDERR, "Measuring baseline (empty PHP process)...\n");
$baseline = measure_massif('baseline', '/* noop */', 0);

// ── Run measurements ────────────────────────────────────────────────────

$div = str_repeat('━', 72);
echo "\n$div\n";
echo "  Judy Memory Benchmark — Valgrind Massif — "
    . number_format($size) . " elements\n";
echo "$div\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . "\n";
echo "  Baseline (empty PHP): " . ($baseline !== null ? fmt($baseline) : '?') . "\n";
echo "$div\n\n";

$results = [];
$total = count($types);
$i = 0;

foreach ($types as $label => $code) {
    $i++;
    fprintf(STDERR, "  [%d/%d] %s ...\n", $i, $total, $label);
    $peak = measure_massif($label, $code, $size);
    $net  = ($peak !== null && $baseline !== null) ? $peak - $baseline : null;
    $results[$label] = ['peak' => $peak, 'net' => $net];
}

// ── Output table ────────────────────────────────────────────────────────

$w = [24, 14, 14, 12];
printf("\n  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    '', 'Peak heap', 'Net (−base)', 'Per element');
printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
    str_repeat('─', $w[0]),
    str_repeat('─', $w[1]),
    str_repeat('─', $w[2]),
    str_repeat('─', $w[3]));

foreach ($results as $label => $r) {
    $peak = $r['peak'] !== null ? fmt($r['peak']) : '?';
    $net  = $r['net']  !== null ? fmt($r['net'])  : '?';
    $per  = ($r['net'] !== null && $size > 0)
        ? sprintf('%.1f B', $r['net'] / $size)
        : '?';

    printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s\n",
        $label, $peak, $net, $per);
}

echo "\n$div\n";
echo "  Notes:\n";
echo "  • Peak heap: Valgrind Massif peak mem_heap_B (malloc'd only; PHP emalloc/mmap not tracked)\n";
echo "  • Net: peak − baseline (PHP interpreter malloc overhead subtracted)\n";
echo "  • Per element: net / $size\n";
echo "  • Baseline: " . ($baseline !== null ? fmt($baseline) : '?') . "\n";
echo "  • PHP array shows ~0 because PHP uses emalloc (mmap'd), not malloc — see\n";
echo "    judy-bench-all-types.php 'Heap delta' column for PHP-level memory comparison\n";
echo "  • Judy types show libJudy.so malloc overhead — this fills the gap for\n";
echo "    STRING_TO_* types where memoryUsage() returns null\n";
echo "$div\n";

// ── Helpers ──────────────────────────────────────────────────────────────

function fmt(int $bytes): string {
    if ($bytes <= 0)         return '—';
    if ($bytes >= 1 << 20)   return sprintf('%.1f MB', $bytes / (1 << 20));
    if ($bytes >= 1 << 10)   return sprintf('%.1f KB', $bytes / (1 << 10));
    return $bytes . ' B';
}
