<?php
/**
 * Deterministic stand-in for judy-bench.php, used by bench_compare_drift_001.phpt
 * to drive scripts/bench-compare.php with known inputs.
 *
 * Emits two timing benchmarks (fake.read, fake.write). The baseline arm always
 * reports 10 ms; the current arm reports 10 ms scaled by FAKE_JUDY_RATIO for
 * the .judy entries and FAKE_PHP_RATIO for the .php control entries.
 * FAKE_JUDY_RATIO may be a comma-separated list applied per benchmark in order.
 * Leaving FAKE_PHP_RATIO unset omits the control entries entirely.
 *
 * The arm is identified by the per-arm ini directory bench-compare.php puts in
 * PHP_INI_SCAN_DIR ("<tmp>/baseline-ini" vs "<tmp>/current-ini").
 */

$opts = getopt('', ['group:', 'size:', 'iterations:', 'json:']);

$arm = strpos((string)getenv('PHP_INI_SCAN_DIR'), 'baseline-ini') !== false
    ? 'baseline' : 'current';

$judy_ratios = array_map('floatval', explode(',', (string)(getenv('FAKE_JUDY_RATIO') ?: '1.0')));
$php_ratio   = getenv('FAKE_PHP_RATIO');   // false => no control rows

$base  = 10.0;
$bench = [];
foreach (['read', 'write'] as $i => $op) {
    $ratio = $judy_ratios[$i % count($judy_ratios)];
    $bench["fake.$op.judy"] = [
        'median_ms' => $arm === 'current' ? $base * $ratio : $base,
    ];
    if ($php_ratio !== false) {
        $bench["fake.$op.php"] = [
            'median_ms' => $arm === 'current' ? $base * (float)$php_ratio : $base,
        ];
    }
}

file_put_contents($opts['json'], json_encode([
    'metadata'   => ['judy_version' => "fake-$arm"],
    'benchmarks' => $bench,
]));
