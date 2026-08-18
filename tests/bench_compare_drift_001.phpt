--TEST--
bench-compare: PHP control decides contamination; uniform real shifts keep per-cell verdicts
--SKIPIF--
<?php
if (substr(PHP_OS, 0, 3) === 'WIN') die('skip POSIX shell required');
$root = dirname(__DIR__);
if (!is_file("$root/scripts/bench-compare.php")) die('skip scripts/bench-compare.php not present');
if (!is_file("$root/modules/judy.so")) die('skip requires in-tree build (modules/judy.so)');
$ini = php_ini_loaded_file();
if ($ini !== false && preg_match('#^\s*(zend_)?extension\s*=.*judy#mi', (string)@file_get_contents($ini))) {
    die('skip main php.ini loads judy; bench-compare preflight refuses this environment');
}
?>
--FILE--
<?php
$root   = dirname(__DIR__);
$so     = "$root/modules/judy.so";
$script = "$root/scripts/bench-compare.php";
$fake   = __DIR__ . '/bench_compare_fake_bench.inc';

function run_compare(string $label, array $env): void {
    global $so, $script, $fake;

    $out = tempnam(sys_get_temp_dir(), 'bcd');
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }
    $cmd = $prefix . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($script)
        . ' --baseline-so ' . escapeshellarg($so)
        . ' --current-so ' . escapeshellarg($so)
        . ' --bench ' . escapeshellarg($fake)
        . ' --groups fake --rounds 3 --quiet'
        . ' --out ' . escapeshellarg($out)
        . ' 2>&1';
    exec($cmd, $output, $status);

    echo "== $label ==\n";
    echo "exit=$status\n";
    $j = json_decode((string)file_get_contents($out), true);
    @unlink($out);
    if (!is_array($j)) {
        echo "no json; output:\n" . implode("\n", $output) . "\n";
        return;
    }

    $d = $j['drift'];
    printf("drift: %+.2f\n", $d['median_delta_pct']);
    printf("control: %s\n", $d['php_control_median_delta_pct'] === null
        ? 'n/a' : sprintf('%+.2f', $d['php_control_median_delta_pct']));
    printf("detector: %s\n", $d['detector']);
    printf("common_mode: %+.2f\n", $d['common_mode_pct']);
    printf("contaminated: %s\n", $d['contaminated'] ? 'true' : 'false');
    $c = $j['counts'];
    printf("counts: faster=%d slower=%d same=%d unstable=%d\n",
        $c['faster'], $c['slower'], $c['same'], $c['unstable']);
    foreach ($j['benchmarks'] as $name => $row) {
        $adj = (float)$row['adj_delta_pct'];
        if (abs($adj) < 0.005) {
            $adj = 0.0;   // normalise -0.00 vs +0.00
        }
        printf("%s: %s %+.2f%s\n", $name, $row['status'], $adj,
            isset($row['reason']) ? ' (' . $row['reason'] . ')' : '');
    }
}

// A uniform shift of every .judy cell over a flat control is a real,
// build-wide change: the per-cell verdicts must stand, in both directions.
run_compare('uniform improvement, flat control',
    ['FAKE_JUDY_RATIO' => '0.8', 'FAKE_PHP_RATIO' => '1.0']);
run_compare('uniform regression, flat control',
    ['FAKE_JUDY_RATIO' => '1.25', 'FAKE_PHP_RATIO' => '1.0']);

// When the control itself moved, the runner changed speed under the
// measurement: the run is contaminated and would-be flags are suppressed.
run_compare('control moved: contaminated, flags suppressed',
    ['FAKE_JUDY_RATIO' => '1.2,1.5', 'FAKE_PHP_RATIO' => '1.2']);

// With no usable control there is no second read on the runner, so a
// run-wide shift falls back to being treated as contamination.
run_compare('no usable control: fallback contamination',
    ['FAKE_JUDY_RATIO' => '1.2']);
?>
--EXPECT--
== uniform improvement, flat control ==
exit=0
drift: -20.00
control: +0.00
detector: php_control
common_mode: +0.00
contaminated: false
counts: faster=2 slower=0 same=0 unstable=0
fake.read: FASTER -20.00
fake.write: FASTER -20.00
== uniform regression, flat control ==
exit=0
drift: +25.00
control: +0.00
detector: php_control
common_mode: +0.00
contaminated: false
counts: faster=0 slower=2 same=0 unstable=0
fake.read: SLOWER +25.00
fake.write: SLOWER +25.00
== control moved: contaminated, flags suppressed ==
exit=0
drift: +35.00
control: +20.00
detector: php_control
common_mode: +35.00
contaminated: true
counts: faster=0 slower=0 same=2 unstable=0
fake.read: ~same -11.11 (suppressed: run flagged contaminated)
fake.write: ~same +11.11 (suppressed: run flagged contaminated)
== no usable control: fallback contamination ==
exit=0
drift: +20.00
control: n/a
detector: judy_median_fallback
common_mode: +20.00
contaminated: true
counts: faster=0 slower=0 same=2 unstable=0
fake.read: ~same +0.00 (within ±10%)
fake.write: ~same +0.00 (within ±10%)
