<?php
/**
 * Cross-Version Benchmark Comparison Tool
 *
 * Compares two JSON outputs from judy-bench.php and produces a delta report.
 * Exit code 1 if any regression exceeds the threshold (for CI gating).
 *
 * Usage:
 *   php judy-bench-compare.php baseline.json current.json [--threshold N] [--json output.json]
 *
 * Options:
 *   --threshold N   Percentage threshold for "significant" change (default: 5)
 *   --json FILE     Write structured comparison results to JSON file
 *
 * Examples:
 *   php judy-bench-compare.php v2.3.0.json v2.4.0.json
 *   php judy-bench-compare.php v2.3.0.json v2.4.0.json --threshold 10
 *   php judy-bench-compare.php v2.3.0.json v2.4.0.json --json delta.json
 */

// ── Parse arguments ─────────────────────────────────────────────────────────

$positional = [];
$threshold = 5.0;
$json_output = null;

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--threshold' && isset($argv[$i + 1])) {
        $threshold = (float)$argv[++$i];
    } elseif ($argv[$i] === '--json' && isset($argv[$i + 1])) {
        $json_output = $argv[++$i];
    } elseif ($argv[$i][0] !== '-') {
        $positional[] = $argv[$i];
    }
}

if (count($positional) < 2) {
    fwrite(STDERR, "Usage: php judy-bench-compare.php baseline.json current.json [--threshold N] [--json output.json]\n");
    exit(2);
}

$baseline_file = $positional[0];
$current_file  = $positional[1];

// ── Load JSON files ─────────────────────────────────────────────────────────

function load_json(string $path): array {
    if (!file_exists($path)) {
        fwrite(STDERR, "ERROR: File not found: $path\n");
        exit(2);
    }
    $data = json_decode(file_get_contents($path), true);
    if ($data === null) {
        fwrite(STDERR, "ERROR: Invalid JSON in $path: " . json_last_error_msg() . "\n");
        exit(2);
    }
    if (!isset($data['benchmarks']) || !isset($data['metadata'])) {
        fwrite(STDERR, "ERROR: Missing 'benchmarks' or 'metadata' key in $path\n");
        exit(2);
    }
    return $data;
}

$baseline = load_json($baseline_file);
$current  = load_json($current_file);

// ── Compare ─────────────────────────────────────────────────────────────────

$all_keys = array_unique(array_merge(
    array_keys($baseline['benchmarks']),
    array_keys($current['benchmarks'])
));
sort($all_keys);

$comparisons = [];
$stats = ['faster' => 0, 'slower' => 0, 'same' => 0, 'new' => 0, 'removed' => 0];
$regressions = [];

foreach ($all_keys as $id) {
    // Skip heap-only entries (median_ms = 0, only has heap_bytes)
    $b_entry = $baseline['benchmarks'][$id] ?? null;
    $c_entry = $current['benchmarks'][$id]  ?? null;

    $b_ms = $b_entry['median_ms'] ?? null;
    $c_ms = $c_entry['median_ms'] ?? null;

    // Skip entries that are heap-only (median = 0)
    if ($b_ms !== null && $b_ms == 0 && isset($b_entry['heap_bytes'])) continue;
    if ($c_ms !== null && $c_ms == 0 && isset($c_entry['heap_bytes'])) continue;

    $entry = ['id' => $id, 'baseline_ms' => $b_ms, 'current_ms' => $c_ms];

    if ($b_ms === null) {
        $entry['status'] = 'NEW';
        $entry['delta_pct'] = null;
        $stats['new']++;
    } elseif ($c_ms === null) {
        $entry['status'] = 'REMOVED';
        $entry['delta_pct'] = null;
        $stats['removed']++;
    } elseif ($b_ms <= 0) {
        $entry['status'] = '~same';
        $entry['delta_pct'] = 0;
        $stats['same']++;
    } else {
        $delta_pct = (($c_ms - $b_ms) / $b_ms) * 100;
        $entry['delta_pct'] = round($delta_pct, 1);

        if ($delta_pct < -$threshold) {
            $entry['status'] = 'FASTER';
            $stats['faster']++;
        } elseif ($delta_pct > $threshold) {
            $entry['status'] = 'SLOWER';
            $stats['slower']++;
            $regressions[] = $entry;
        } else {
            $entry['status'] = '~same';
            $stats['same']++;
        }
    }

    $comparisons[] = $entry;
}

// ── Human-readable output ───────────────────────────────────────────────────

$div = str_repeat('═', 90);
$dash = str_repeat('─', 90);

$b_ver = $baseline['metadata']['judy_version'] ?? '?';
$c_ver = $current['metadata']['judy_version']  ?? '?';
$b_size = $baseline['metadata']['size'] ?? '?';
$c_size = $current['metadata']['size']  ?? '?';

echo "$div\n";
echo "  Benchmark Comparison: v$b_ver -> v$c_ver\n";
echo "  Baseline: $baseline_file ($b_size elements)\n";
echo "  Current:  $current_file ($c_size elements)\n";
echo "  Threshold: +/- {$threshold}%\n";
echo "$div\n\n";

// Group by suite prefix
$suites = [];
foreach ($comparisons as $c) {
    $parts = explode('.', $c['id'], 2);
    $suite = $parts[0] ?? 'other';
    $suites[$suite][] = $c;
}

$col = [42, 12, 12, 10, 8];

foreach ($suites as $suite_name => $entries) {
    echo "── Suite: $suite_name " . str_repeat('─', max(0, 75 - strlen($suite_name))) . "\n\n";

    printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %-{$col[4]}s\n",
        'Test', 'Baseline', 'Current', 'Delta', 'Status');
    printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %-{$col[4]}s\n",
        str_repeat('─', $col[0]), str_repeat('─', $col[1]),
        str_repeat('─', $col[2]), str_repeat('─', $col[3]),
        str_repeat('─', $col[4]));

    foreach ($entries as $e) {
        $b_str = $e['baseline_ms'] !== null ? sprintf('%.2f ms', $e['baseline_ms']) : 'N/A';
        $c_str = $e['current_ms']  !== null ? sprintf('%.2f ms', $e['current_ms'])  : 'N/A';

        if ($e['delta_pct'] !== null) {
            $sign = $e['delta_pct'] >= 0 ? '+' : '';
            $delta_str = $sign . $e['delta_pct'] . '%';
        } else {
            $delta_str = '—';
        }

        $status_str = $e['status'];
        if ($e['status'] === 'FASTER' && $e['delta_pct'] !== null && $e['delta_pct'] < -20) {
            $status_str .= ' *';
        }
        if ($e['status'] === 'SLOWER') {
            $status_str .= ' !';
        }

        // Shorten the ID for display
        $display_id = $e['id'];
        // Remove suite prefix for readability within the group
        $parts = explode('.', $display_id, 2);
        $display_id = $parts[1] ?? $display_id;

        printf("  %-{$col[0]}s  %{$col[1]}s  %{$col[2]}s  %{$col[3]}s  %-{$col[4]}s\n",
            $display_id, $b_str, $c_str, $delta_str, $status_str);
    }
    echo "\n";
}

// Summary
echo "$dash\n";
echo "  Summary: {$stats['faster']} faster, {$stats['slower']} slower, {$stats['same']} unchanged";
if ($stats['new'] > 0) echo ", {$stats['new']} new";
if ($stats['removed'] > 0) echo ", {$stats['removed']} removed";
echo "\n";

if (!empty($regressions)) {
    echo "  Regressions (>{$threshold}%):";
    foreach ($regressions as $r) {
        echo " " . $r['id'] . " +" . $r['delta_pct'] . "%";
    }
    echo "\n";
}
echo "$div\n";

// ── JSON output ─────────────────────────────────────────────────────────────

if ($json_output !== null) {
    $json_data = [
        'metadata' => [
            'baseline_version' => $b_ver,
            'current_version'  => $c_ver,
            'baseline_file'    => $baseline_file,
            'current_file'     => $current_file,
            'threshold_pct'    => $threshold,
            'date'             => date('Y-m-d\TH:i:sP'),
        ],
        'summary' => $stats,
        'regressions' => array_map(fn($r) => ['id' => $r['id'], 'delta_pct' => $r['delta_pct']], $regressions),
        'comparisons' => $comparisons,
    ];

    $json = json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($json_output, $json . "\n") !== false) {
        echo "\n  JSON comparison written to: $json_output\n";
    } else {
        fwrite(STDERR, "ERROR: Could not write JSON to $json_output\n");
    }
}

// ── Exit code ───────────────────────────────────────────────────────────────

if (!empty($regressions)) {
    exit(1);
}
exit(0);
