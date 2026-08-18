<?php
/**
 * Interleaved A/B benchmark comparison driver.
 *
 * Problem this solves
 * -------------------
 * Running the whole baseline suite to completion and *then* the whole current
 * suite puts the two arms minutes apart. Taking a median inside an arm removes
 * fast jitter but nothing removes drift *between* arms, so when a shared CI
 * runner slows down during the second arm every current number inflates at
 * once and the comparison reports a wall of false regressions.
 *
 * What this driver does instead
 * -----------------------------
 *  1. Interleaves the arms per benchmark *group* rather than per suite. Each
 *     group's baseline and current measurements are taken adjacent in time
 *     (seconds apart, not minutes), so slow drift affects both arms about
 *     equally and largely cancels in the delta.
 *  2. Repeats the interleaved pair over R rounds in ABBA order (round 1 runs
 *     baseline-then-current, round 2 current-then-baseline, ...). ABBA makes
 *     the two arms symmetric in time, which cancels the linear component of
 *     drift exactly instead of loading it onto whichever arm ran second.
 *  3. Treats each round as a *pair* and works on the per-round ratio
 *     current/baseline, summarised as a median with a 95% percentile-bootstrap
 *     CI (same method as examples/benchmarks/judy-bench-alternatives.php).
 *     Pairing is what makes the interleaving pay: whatever the machine was
 *     doing during round r hits both members of that round's pair, so it
 *     divides out of the ratio instead of accumulating into a delta between
 *     two independently-estimated medians.
 *  4. Uses the PHP-array control rows as the contamination detector. The
 *     control runs no judy code, so it moves only when the runner itself
 *     changed speed; when its median delta passes the threshold the whole
 *     comparison is marked contaminated rather than emitting a page of
 *     individual flags. The .judy run-wide median is NOT the detector: a
 *     uniform .judy shift over a flat control is a real, build-wide change
 *     (a libJudy-wide win or regression moves every cell together) and must
 *     reach the per-cell verdicts. Only when no control row clears the
 *     min-ms floor does the .judy median fall back to being the detector.
 *  5. Divides each benchmark's ratio by the runner movement the control
 *     measured, so runner speed divides out of every cell while whatever the
 *     extension really did — uniform or not — stays in.
 *
 * A benchmark is flagged only when the *whole* drift-adjusted CI clears the
 * threshold — a point estimate past the threshold with a CI straddling it is
 * reported as "no measured separation", not as a regression — and both arms
 * are above the minimum-duration floor.
 *
 * What a tight CI does NOT prove
 * -----------------------------
 * Interleaving cancels drift *between* arms. It cannot cancel an effect that
 * makes one arm slower on one runner for the whole run — page/cache layout of
 * that particular binary on that particular machine. Such an effect is
 * internally consistent, so it produces a tight CI clear of the threshold and
 * looks exactly like a real regression.
 *
 * A tight CI therefore means "consistent within this run", not "reproducible".
 * Before believing a lone flag, re-run the same code: a real effect recurs, a
 * layout artifact does not. Worked example — `api.setop.diff.bitset` was
 * flagged +11.6% [+10.4, +11.9] on one run and read +0.9% on the very next
 * run of the *same commit* after merge, +0.0% over 11 paired rounds on an idle
 * host, with the BITSET branch of Judy::diff() byte-identical between the two
 * arms and both linking the same system libJudy. Across the 23 runs on record
 * it is the only SLOWER flag ever emitted out of 1610 evaluated rows, while
 * the genuine adv.filter.* win recurs in 22 of 23. Recurrence is the signal.
 *
 * Usage:
 *   php scripts/bench-compare.php \
 *       --baseline-so /path/to/baseline/judy.so \
 *       --current-so  /path/to/modules/judy.so \
 *       [--rounds 5] [--size 300000] [--iterations 3] \
 *       [--groups core.int,core.str,api.batch,api.setops,adv.iter] \
 *       [--threshold 10.0] [--min-ms 2.0] [--drift-threshold 5.0] \
 *       [--out bench-compare.json]
 *
 * Output: a JSON document (see $result at the bottom) consumed by the
 * "Release Comparison" section of the CI report.
 */

// ── CLI ─────────────────────────────────────────────────────────────────────

$opts = getopt('', [
    'baseline-so:', 'current-so:', 'bench:', 'rounds:', 'size:', 'iterations:',
    'groups:', 'threshold:', 'min-ms:', 'drift-threshold:', 'max-spread:',
    'out:', 'quiet',
]);

$baseline_so = $opts['baseline-so'] ?? null;
$current_so  = $opts['current-so']  ?? null;
if ($baseline_so === null || $current_so === null) {
    fwrite(STDERR, "Usage: bench-compare.php --baseline-so <path> --current-so <path> [options]\n");
    exit(2);
}
foreach (['baseline' => $baseline_so, 'current' => $current_so] as $arm => $so) {
    if (!is_file($so)) {
        fwrite(STDERR, "No such $arm extension: $so\n");
        exit(2);
    }
}

$bench_script = $opts['bench'] ?? __DIR__ . '/../examples/benchmarks/judy-bench.php';
if (!is_file($bench_script)) {
    fwrite(STDERR, "No such benchmark script: $bench_script\n");
    exit(2);
}

$rounds     = max(3, (int)($opts['rounds']     ?? 5));
$size       = max(1, (int)($opts['size']       ?? 300000));
$iterations = max(1, (int)($opts['iterations'] ?? 3));
$threshold  = (float)($opts['threshold']       ?? 10.0);
$min_ms     = (float)($opts['min-ms']          ?? 2.0);
$drift_max  = (float)($opts['drift-threshold'] ?? 5.0);
$max_spread = (float)($opts['max-spread']      ?? 30.0);
$out_file   = $opts['out'] ?? 'bench-compare.json';
$quiet      = isset($opts['quiet']);

// adv.sso is excluded by default: its entries do not end in `.judy`, so the
// release comparison never looks at them, and running them would cost wall
// clock for nothing.
$groups = array_values(array_filter(array_map(
    'trim',
    explode(',', (string)($opts['groups'] ?? 'core.int,core.str,api.batch,api.setops,adv.iter'))
), 'strlen'));

function say(string $msg): void {
    global $quiet;
    if (!$quiet) {
        fwrite(STDERR, $msg);
    }
}

// ── Per-arm ini scan dirs ───────────────────────────────────────────────────
// Children get exactly one judy build via PHP_INI_SCAN_DIR, so the baseline
// and the current build can never end up loaded in the same process.

$tmp_root = sys_get_temp_dir() . '/judy-bench-compare-' . getmypid();
$arm_ini  = [];
foreach (['baseline' => $baseline_so, 'current' => $current_so] as $arm => $so) {
    $dir = "$tmp_root/$arm-ini";
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        fwrite(STDERR, "Cannot create $dir\n");
        exit(2);
    }
    file_put_contents("$dir/judy.ini", 'extension=' . realpath($so) . "\n");
    $arm_ini[$arm] = $dir;
}
register_shutdown_function(static function () use ($tmp_root): void {
    foreach (glob("$tmp_root/*/*") ?: [] as $f) { @unlink($f); }
    foreach (glob("$tmp_root/*") ?: [] as $d) { @rmdir($d); }
    @rmdir($tmp_root);
});

// ── Child execution ─────────────────────────────────────────────────────────

/** Build the `PHP_INI_SCAN_DIR=... php ...` prefix for one arm's child. */
function arm_php(string $arm): string {
    global $arm_ini;

    // PHP_INI_SCAN_DIR points at a directory holding exactly one judy.ini, so
    // no other ini file on the machine can also load the extension. The whole
    // comparison is worthless if an arm silently runs the other build: an
    // image that pre-enables judy in its own conf.d makes `-d extension=...`
    // a no-op and PHP only whispers "Module already loaded" about it. Hence
    // the scan-dir override here and verify_arm() below.
    return 'PHP_INI_SCAN_DIR=' . escapeshellarg($arm_ini[$arm]) . ' '
        . escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 ';
}

/**
 * Fail loudly unless this arm's children load exactly the extension we asked
 * for. A benchmark that silently measures the wrong binary is worse than a
 * noisy one: it reports a near-zero delta, which reads as success.
 */
function verify_arm(string $arm, string $so): void {
    global $tmp_root;

    $probe = <<<'PROBE'
$out = ['loaded' => extension_loaded('judy'), 'paths' => [], 'proc' => false];
$maps = @file_get_contents('/proc/self/maps');
if ($maps !== false) {
    $out['proc'] = true;
    if (preg_match_all('#/\S*judy\S*\.so#', $maps, $m)) {
        $out['paths'] = array_values(array_unique($m[0]));
    }
}
$inis = php_ini_scanned_files();
$out['ini_files'] = $inis === false ? [] : array_values(array_filter(
    array_map('trim', explode(',', $inis)), 'strlen'
));
$loaded_ini = php_ini_loaded_file();
$out['main_ini_loads_judy'] = $loaded_ini !== false
    && preg_match('#^\s*(zend_)?extension\s*=.*judy#mi', (string)@file_get_contents($loaded_ini)) === 1;
echo json_encode($out);
PROBE;

    $err = "$tmp_root/probe.err";
    $cmd = arm_php($arm) . '-r ' . escapeshellarg($probe) . ' 2> ' . escapeshellarg($err);
    $raw = (string)shell_exec($cmd);
    $stderr = is_file($err) ? trim((string)file_get_contents($err)) : '';
    @unlink($err);

    $fail = static function (string $why) use ($arm, $so, $stderr): void {
        fwrite(STDERR, "Extension preflight failed for the $arm arm.\n");
        fwrite(STDERR, "  wanted: $so\n");
        fwrite(STDERR, "  reason: $why\n");
        if ($stderr !== '') {
            fwrite(STDERR, "  child stderr: $stderr\n");
        }
        exit(1);
    };

    if (stripos($stderr, 'already loaded') !== false) {
        $fail('another ini file had already loaded judy, so this arm would '
            . 'have measured whichever build that ini points at');
    }
    if (stripos($stderr, 'Unable to load dynamic library') !== false) {
        $fail('the extension could not be loaded');
    }

    $info = json_decode(trim($raw), true);
    if (!is_array($info)) {
        $fail('the probe produced no usable output: ' . trim($raw));
    }
    if (empty($info['loaded'])) {
        $fail('the judy extension was not loaded at all');
    }
    if (!empty($info['main_ini_loads_judy'])) {
        $fail('the main php.ini also loads judy; PHP_INI_SCAN_DIR cannot '
            . 'override that, so run this on a PHP whose php.ini does not '
            . 'enable the extension');
    }
    if (count($info['ini_files']) > 1) {
        $fail('more than one scanned ini file is in effect: '
            . implode(', ', $info['ini_files']));
    }

    // Linux gives us the authoritative answer: which file is actually mapped.
    if (!empty($info['proc'])) {
        $want  = realpath($so);
        $paths = array_map(static fn($p) => realpath($p) ?: $p, $info['paths']);
        $paths = array_values(array_unique($paths));
        if (count($paths) !== 1 || $paths[0] !== $want) {
            $fail('the mapped extension is ' . (
                $paths ? implode(', ', $paths) : 'not identifiable'
            ));
        }
    }
}

/**
 * Run one benchmark group under one arm. Returns the child's `benchmarks` map.
 *
 * @return array<string,array<string,mixed>>
 */
function run_group(string $arm, string $group): array {
    global $bench_script, $size, $iterations, $tmp_root;

    $json = "$tmp_root/out.json";
    $err  = "$tmp_root/child.err";
    @unlink($json);
    $cmd = arm_php($arm)
        . escapeshellarg($bench_script)
        . ' --group ' . escapeshellarg($group)
        . ' --size ' . $size
        . ' --iterations ' . $iterations
        . ' --json ' . escapeshellarg($json)
        . ' > /dev/null 2> ' . escapeshellarg($err);

    exec($cmd, $_, $status);
    $stderr = is_file($err) ? trim((string)file_get_contents($err)) : '';
    @unlink($err);

    // Never measure through a warning that means the wrong build is loaded.
    if (stripos($stderr, 'already loaded') !== false
        || stripos($stderr, 'Unable to load dynamic library') !== false) {
        fwrite(STDERR, "Extension loading went wrong mid-run (arm=$arm "
            . "group=$group): $stderr\n");
        exit(1);
    }
    if ($status !== 0 || !is_file($json)) {
        fwrite(STDERR, "Child failed (arm=$arm group=$group status=$status)\n");
        if ($stderr !== '') {
            fwrite(STDERR, "  stderr: $stderr\n");
        }
        exit(1);
    }
    $data = json_decode((string)file_get_contents($json), true);
    if (!is_array($data) || !isset($data['benchmarks'])) {
        fwrite(STDERR, "Child produced unusable JSON (arm=$arm group=$group)\n");
        exit(1);
    }
    $GLOBALS['arm_meta'][$arm] = $data['metadata'] ?? [];

    return $data['benchmarks'];
}

// ── Statistics ──────────────────────────────────────────────────────────────

function median(array $xs): float {
    sort($xs);
    $c = count($xs);
    if ($c === 0) {
        return 0.0;
    }

    return $c % 2 ? (float)$xs[intdiv($c, 2)] : ((float)$xs[$c / 2 - 1] + (float)$xs[$c / 2]) / 2;
}

/**
 * 95% percentile-bootstrap CI for the median. Seeded, so re-deriving the stats
 * from the same samples reproduces the same interval.
 *
 * @return array{0:float,1:float}
 */
function median_ci(array $xs, int $resamples = 2000): array {
    $n = count($xs);
    if ($n < 3) {
        return [(float)min($xs), (float)max($xs)];
    }
    mt_srand(20260817);
    $meds = [];
    for ($b = 0; $b < $resamples; $b++) {
        $s = [];
        for ($i = 0; $i < $n; $i++) {
            $s[] = $xs[mt_rand(0, $n - 1)];
        }
        $meds[] = median($s);
    }
    sort($meds);

    return [$meds[(int)floor(0.025 * $resamples)], $meds[(int)ceil(0.975 * $resamples) - 1]];
}


/**
 * Within-arm spread as a percentage of the median: (max - min) / median * 100.
 *
 * A benchmark whose own arm scatters this much was not measured cleanly, so
 * neither is the ratio built from it. The run-wide drift statistic only sees
 * contamination that hits everything at once; this catches the localized kind
 * that lands on one benchmark's window.
 */
function spread_pct(array $xs): float {
    $m = median($xs);
    if ($m <= 0.0) {
        return 0.0;
    }

    return (max($xs) - min($xs)) / $m * 100.0;
}

// ── Interleaved execution ───────────────────────────────────────────────────

$arm_meta = [];
/** @var array<string,array<string,array<float>>> $samples arm => id => ms[] */
$samples  = ['baseline' => [], 'current' => []];
/** @var array<string,array<string,array<int>>> $heaps */
$heaps    = ['baseline' => [], 'current' => []];

// Preflight before spending any wall clock: prove each arm loads the build it
// was given, and say plainly when the two arms are the same file.
verify_arm('baseline', $baseline_so);
verify_arm('current', $current_so);
$same_build = hash_file('sha256', $baseline_so) === hash_file('sha256', $current_so);
if ($same_build) {
    say("Note: both arms are byte-identical builds — this is a self-comparison,\n"
        . "      so any flagged difference is measurement error by construction.\n");
}
say("Preflight OK: baseline and current arms load their own build.\n");

$started = microtime(true);
$total   = count($groups) * $rounds * 2;
$done    = 0;

foreach ($groups as $group) {
    for ($r = 1; $r <= $rounds; $r++) {
        // ABBA: alternate which arm goes first so the two arms are symmetric
        // in time. Linear drift then cancels instead of being charged to
        // whichever arm always runs second.
        $order = ($r % 2 === 1) ? ['baseline', 'current'] : ['current', 'baseline'];
        foreach ($order as $arm) {
            $bm = run_group($arm, $group);
            foreach ($bm as $id => $entry) {
                if (array_key_exists('heap_bytes', $entry)) {
                    $heaps[$arm][$id][] = (int)$entry['heap_bytes'];
                    continue;
                }
                $samples[$arm][$id][] = (float)$entry['median_ms'];
            }
            $done++;
            say(sprintf("  [%2d/%2d] %-12s %-10s round %d\n", $done, $total, $group, $arm, $r));
        }
    }
}
$wall = microtime(true) - $started;

// ── Per-benchmark comparison ────────────────────────────────────────────────

$ids = array_unique(array_merge(
    array_keys($samples['baseline']),
    array_keys($samples['current'])
));
sort($ids);

$rows     = [];   // id => stats (only .judy entries)
$new      = [];
$removed  = [];
$raw_deltas = [];         // .judy point deltas, for the run-wide median
$php_deltas = [];         // .php control deltas, reported as a diagnostic

foreach ($ids as $id) {
    $b = $samples['baseline'][$id] ?? null;
    $c = $samples['current'][$id]  ?? null;

    if ($b === null || $c === null) {
        if (!str_ends_with($id, '.judy')) {
            continue;
        }
        $name = substr($id, 0, -5);
        if ($b === null) {
            $new[$name] = round(median($c), 4);
        } else {
            $removed[$name] = round(median($b), 4);
        }
        continue;
    }

    $b_med = median($b);
    $c_med = median($c);
    if ($b_med <= 0.0) {
        continue;
    }

    // Per-round pairing. Sample r of each arm came from the same round, i.e.
    // from two child processes run back to back, so the pair shares a machine
    // state and the ratio cancels it.
    $pairs = [];
    $n     = min(count($b), count($c));
    for ($r = 0; $r < $n; $r++) {
        if ($b[$r] > 0.0) {
            $pairs[] = $c[$r] / $b[$r];
        }
    }
    if (!$pairs) {
        continue;
    }
    $ratio = median($pairs);

    if (str_ends_with($id, '.php')) {
        // PHP-array control work. It shares a process and a moment with the
        // matching .judy measurement, so its delta is a second, independent
        // read on how much the machine moved between the arms.
        if ($b_med >= $min_ms && $c_med >= $min_ms) {
            $php_deltas[] = ($ratio - 1.0) * 100.0;
        }
        continue;
    }
    if (!str_ends_with($id, '.judy')) {
        continue;
    }

    $rows[substr($id, 0, -5)] = [
        'baseline_ms'   => round($b_med, 4),
        'current_ms'    => round($c_med, 4),
        'baseline_runs' => array_map(static fn($v) => round($v, 4), $b),
        'current_runs'  => array_map(static fn($v) => round($v, 4), $c),
        'paired_ratios' => array_map(static fn($v) => round($v, 4), $pairs),
        'ratio'         => $ratio,
        'ratio_ci'      => median_ci($pairs),
        'delta_pct'     => round(($ratio - 1.0) * 100.0, 2),
        'spread_pct'    => [round(spread_pct($b), 1), round(spread_pct($c), 1)],
    ];
    if ($b_med >= $min_ms || $c_med >= $min_ms) {
        $raw_deltas[] = ($ratio - 1.0) * 100.0;
    }
}

// ── Run-wide drift / contamination ──────────────────────────────────────────

$drift     = $raw_deltas ? median($raw_deltas) : 0.0;
$php_drift = $php_deltas ? median($php_deltas) : null;

// The control decides contamination. Thresholding the .judy median instead
// would read any uniform shift as runner noise — muting a build-wide win and,
// worse, reporting a build-wide regression as "same" with CI green.
if ($php_drift === null) {
    // No control row cleared the min-ms floor; without a second read on the
    // runner, a run-wide shift can only be treated as runner movement.
    $contaminated = abs($drift) > $drift_max;
    $common_mode  = $drift;
} else {
    $contaminated = abs($php_drift) > $drift_max;
    // Divide out what the control measured, not the .judy median: dividing by
    // the .judy median re-centres the population on itself and erases exactly
    // the uniform real change the control just vouched for. Under
    // contamination the flags are suppressed anyway, so there the .judy
    // median stays the better re-centring for the diagnostic numbers.
    $common_mode  = $contaminated ? $drift : $php_drift;
}
$scale = 1.0 + $common_mode / 100.0;   // multiplicative common-mode factor

$counts = ['faster' => 0, 'slower' => 0, 'same' => 0, 'unstable' => 0,
           'new' => count($new), 'removed' => count($removed)];

$hi_bound = 1.0 + $threshold / 100.0;
$lo_bound = 1.0 - $threshold / 100.0;

foreach ($rows as $name => &$row) {
    $b_med = $row['baseline_ms'];
    $c_med = $row['current_ms'];

    // Divide out the run-wide common-mode factor.
    $adj_ratio = $row['ratio'] / $scale;
    $adj_ci    = [$row['ratio_ci'][0] / $scale, $row['ratio_ci'][1] / $scale];

    $row['adj_delta_pct']    = round(($adj_ratio - 1.0) * 100.0, 2);
    $row['adj_ci_delta_pct'] = [round(($adj_ci[0] - 1.0) * 100.0, 2),
                                round(($adj_ci[1] - 1.0) * 100.0, 2)];
    $row['ratio']            = round($row['ratio'], 4);
    $row['ratio_ci']         = array_map(static fn($v) => round($v, 4), $row['ratio_ci']);

    if ($b_med < $min_ms && $c_med < $min_ms) {
        $row['status'] = '~same';
        $row['reason'] = 'below min-ms floor';
        $counts['same']++;
        continue;
    }

    // Refuse to evaluate a benchmark whose own arms scattered by more than the
    // difference being claimed: within-arm noise that large explains the
    // between-arm gap on its own. The guard is relative on purpose — an effect
    // that dwarfs the scatter is still reported, so this suppresses noise
    // without suppressing signal.
    $worst_spread = max($row['spread_pct'][0], $row['spread_pct'][1]);
    if ($worst_spread > $max_spread && $worst_spread > abs($row['adj_delta_pct'])) {
        $row['status'] = 'unstable';
        $row['reason'] = sprintf(
            'not evaluated: within-arm spread %.0f%% exceeds both the %.0f%% floor '
            . 'and the %.1f%% effect',
            $worst_spread, $max_spread, abs($row['adj_delta_pct'])
        );
        $counts['unstable']++;
        continue;
    }

    // The whole interval has to clear the threshold. A point estimate past the
    // threshold whose CI straddles it is not a measured regression.
    if ($adj_ci[0] > $hi_bound) {
        $row['status'] = 'SLOWER';
        $counts['slower']++;
    } elseif ($adj_ci[1] < $lo_bound) {
        $row['status'] = 'FASTER';
        $counts['faster']++;
    } else {
        $row['status'] = '~same';
        $row['reason'] = abs($row['adj_delta_pct']) > $threshold
            ? 'no measured separation (CI straddles the threshold)'
            : sprintf("within \u{00b1}%.0f%%", $threshold);
        $counts['same']++;
    }
}
unset($row);

// A contaminated run keeps its numbers but stops asserting anything about
// them: suppressing the flags is the honest outcome, not silently reporting
// a page of regressions the machine caused.
if ($contaminated) {
    $counts['same'] += $counts['slower'] + $counts['faster'];
    $counts['slower'] = 0;
    $counts['faster'] = 0;
    foreach ($rows as &$row) {
        if ($row['status'] === 'SLOWER' || $row['status'] === 'FASTER') {
            $row['status'] = '~same';
            $row['reason'] = 'suppressed: run flagged contaminated';
        }
    }
    unset($row);
}

// ── Memory (heap) ───────────────────────────────────────────────────────────

$memory = [];
foreach (array_keys($heaps['baseline']) as $id) {
    if (!str_ends_with($id, '.judy') || !isset($heaps['current'][$id])) {
        continue;
    }
    $memory[substr($id, 0, -5)] = [
        'baseline_bytes' => (int)median($heaps['baseline'][$id]),
        'current_bytes'  => (int)median($heaps['current'][$id]),
    ];
}

// ── Emit ────────────────────────────────────────────────────────────────────

$result = [
    'metadata' => [
        'php_version'      => phpversion(),
        'baseline_version' => $arm_meta['baseline']['judy_version'] ?? '?',
        'current_version'  => $arm_meta['current']['judy_version'] ?? '?',
        'platform'         => PHP_OS . ' ' . php_uname('m'),
        'date'             => date('Y-m-d\TH:i:sP'),
        'size'             => $size,
        'iterations'       => $iterations,
        'rounds'           => $rounds,
        'groups'           => $groups,
        'threshold_pct'    => $threshold,
        'min_ms'           => $min_ms,
        'drift_threshold_pct' => $drift_max,
        'max_spread_pct'   => $max_spread,
        'wall_seconds'     => round($wall, 1),
        'baseline_so'      => realpath($baseline_so),
        'current_so'       => realpath($current_so),
        'identical_builds' => $same_build,
        'schema'           => 'judy-bench-compare/1',
    ],
    'drift' => [
        'median_delta_pct'             => round($drift, 2),
        'php_control_median_delta_pct' => $php_drift === null ? null : round($php_drift, 2),
        'detector'                     => $php_drift === null ? 'judy_median_fallback' : 'php_control',
        'common_mode_pct'              => round($common_mode, 2),
        'contaminated'                 => $contaminated,
        'sample_count'                 => count($raw_deltas),
        'php_control_sample_count'     => count($php_deltas),
    ],
    'counts'     => $counts,
    'benchmarks' => $rows,
    'memory'     => $memory,
    'new'        => $new,
    'removed'    => $removed,
];

file_put_contents($out_file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf(
    "Interleaved comparison: %s -> %s | %d groups x %d rounds x 2 arms | %.0fs\n",
    $result['metadata']['baseline_version'],
    $result['metadata']['current_version'],
    count($groups), $rounds, $wall
);
printf(
    "Run-wide median delta: %+.2f%% (PHP-array control %s) -> %s\n",
    $drift,
    $php_drift === null ? 'n/a' : sprintf('%+.2f%%', $php_drift),
    $contaminated
        ? ($php_drift === null
            ? 'CONTAMINATED (no usable control; run-wide shift), flags suppressed'
            : 'CONTAMINATED (control moved), flags suppressed')
        : 'clean'
);
printf(
    "Summary: %d faster, %d regressions, %d unchanged, %d unstable, %d new, %d removed\n",
    $counts['faster'], $counts['slower'], $counts['same'], $counts['unstable'],
    $counts['new'], $counts['removed']
);
foreach ($rows as $name => $row) {
    if ($row['status'] === 'SLOWER' || $row['status'] === 'FASTER') {
        printf("  %-8s %-40s %+.1f%% [%+.1f%%, %+.1f%%] (drift-adjusted; raw %+.1f%%)\n",
            $row['status'], $name, $row['adj_delta_pct'],
            $row['adj_ci_delta_pct'][0], $row['adj_ci_delta_pct'][1],
            $row['delta_pct']);
    }
}
echo "Wrote $out_file\n";
