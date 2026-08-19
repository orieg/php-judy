<?php
/**
 * Recurring cross-platform performance REGRESSION GATE.
 *
 * What this is for
 * ----------------
 * `scripts/bench-threearm.php` answers "what did the vendoring buy?" once, on a
 * quiet dedicated host, in absolute milliseconds. This answers a different and
 * longer-lived question — **"has it got worse since last time, on any platform
 * we support?"** — and it has to answer it on shared CI runners that are far
 * too noisy to compare absolute numbers across days.
 *
 * The one idea that makes that possible
 * -------------------------------------
 * **Never compare a number across runs. Compare a RATIO OF TWO ARMS MEASURED IN
 * THE SAME INTERLEAVED ROUNDS.**
 *
 * Both arms of every ratio below are measured seconds apart on the same runner,
 * in the same rounds, with the arm order alternating. A runner that is 40%
 * slower than yesterday's runner makes both arms 40% slower and the ratio does
 * not move. What survives is what actually changed in the code. The gate then
 * compares this run's ratio against the stored baseline ratio — a ratio of
 * ratios — which is the only quantity on a shared runner that means anything
 * across time.
 *
 * The three axes, and what each one can see that the others cannot
 * ---------------------------------------------------------------
 *   **S -> C  (timing)**  the bundled tree against the *pristine-static* arm.
 *       Both arms share the extension source, the compiler, the flags and the
 *       linkage model; only libjudy/'s contents differ. So this axis sees
 *       exactly one thing: erosion of the vendored libJudy patches. An
 *       extension-level change cancels out of it entirely.
 *
 *   **A -> C  (timing)**  php-judy against a PHP native array, measured inside
 *       ONE process microseconds apart. The array is the invariant reference —
 *       it cannot regress with our code — so this axis catches regressions in
 *       the *extension* as well as the library, which S->C is blind to.
 *       Together the two decompose "php-judy got slower" into "the library did"
 *       and "the extension did".
 *
 *   **A -> C  (memory)**  peak RSS of a child process that builds one structure,
 *       per-arm process floor subtracted. Memory is php-judy's least equivocal
 *       claim and RSS is very nearly deterministic, so this axis gates at a much
 *       tighter threshold than either timing axis and is the most trustworthy
 *       signal a noisy runner can produce.
 *
 * The controls, and why a PHP-array control is not enough
 * ------------------------------------------------------
 *   **PHP-only control** (retained). The `.php` rows of TAM_ARM_A_ROWS execute
 *       no libJudy instruction, so movement on them across arms is pure runner
 *       drift. This is the right instrument for interpreter speed, CPU frequency
 *       and process-startup drift, and it is used to re-centre the timing axes.
 *
 *   **C1-vs-C2 rebuild control** (the load-bearing one). Two independently
 *       linked builds of *identical* source, interleaved into the same rounds as
 *       everything else. Every cell must read null.
 *
 *       This control exists because the PHP-array one is **structurally blind to
 *       the failure mode that matters most here**. PHP array operations are
 *       neither pointer-chasing nor DRAM-bound; Judy's descend is both. When a
 *       co-resident tenant steals last-level cache and memory bandwidth, it
 *       takes exactly what the Judy arms need and barely touches the array
 *       control — measured once on a 24-core host: an untouched baseline arm
 *       moved 2.2x while the PHP-array control read +0.36%. A drift control must
 *       share the memory-access character of the thing it controls or it will
 *       certify a run that contention has already ruined. C1-vs-C2 *is* Judy, so
 *       it has that character by construction.
 *
 *       It does double duty. Its measured scatter is this run's own empirical
 *       noise floor, and the gate threshold is raised to it when it exceeds the
 *       offline-derived floor (see "Thresholds"). A contaminated run therefore
 *       widens its own threshold and cannot cry wolf; it does not get to pass
 *       silently either, because the widened threshold is reported.
 *
 * Thresholds, and how they were derived
 * -------------------------------------
 * Not guessed. Two components, and the larger governs:
 *
 *   1. An OFFLINE FLOOR per platform and per axis, derived by running this gate
 *      repeatedly against the SAME COMMIT on CI and measuring how far each
 *      cell's ratio moves between runs when nothing changed. `--derive` does
 *      that computation from a set of run JSONs and prints the quantiles; the
 *      resulting floors are recorded in the baseline file next to the ratios
 *      they govern, so a reader can see what the number is and where it came
 *      from. They are deliberately NOT the dedicated-host claim floors (~3%
 *      cache-resident, ~1.3% out-of-cache, FINDINGS §11.10): a shared runner is
 *      worse than a dedicated one and pretending otherwise produces a gate that
 *      cries wolf until somebody turns it off.
 *
 *   2. THIS RUN's C1-vs-C2 control scatter. When the runner is having a bad day
 *      the control says so, and the threshold widens to match.
 *
 * A cell is flagged only when the WHOLE bootstrap CI of its current ratio clears
 * `baseline * (1 +/- threshold)` in the ADVERSE direction. A point estimate past
 * the threshold with a straddling CI is reported as movement, not as a
 * regression — the same discipline every other measurement in this project
 * works to. Improvements are reported too, and never fail the gate.
 *
 * Usage
 * -----
 *   # Measure and report (no gating):
 *   php scripts/bench-gate.php \
 *       --arm C=build/judy-C-1.so --arm C=build/judy-C-2.so \
 *       --arm S=build/judy-S-1.so --arm S=build/judy-S-2.so \
 *       --platform linux-glibc-x86_64 --out gate.json
 *
 *   # Gate against the committed baseline (exit 1 on regression):
 *   php scripts/bench-gate.php ... --baseline baselines/arm-ratios.json --gate
 *
 *   # Derive the offline threshold floors from several same-commit runs:
 *   php scripts/bench-gate.php --derive run1.json,run2.json,run3.json
 *
 *   # Refresh the baseline (DEDICATED PR ONLY — see baselines/README):
 *   php scripts/bench-gate.php --derive run1.json,run2.json,run3.json \
 *       --update-baseline baselines/arm-ratios.json
 *
 * Arm roles: `C` is the tree under test (required, >=1, 2 recommended so the
 * rebuild control exists); `S` is the pristine-static reference (required for
 * the S->C axis); `B` is a system libJudy where a package exists, and is
 * measured and reported but NEVER gated — it tracks a distro package that moves
 * independently of this repository, so a change in it is not our regression.
 *
 * Exit codes: 0 pass, 1 regression flagged, 2 usage/environment error,
 * 3 the run could not assert anything (hygiene failure with --gate).
 */

require_once __DIR__ . '/bench-lib.php';

// Before this driver's own CLI parsing: tam_mem_cell() re-execs this file.
tam_mem_child_main($argv);

// ── CLI ─────────────────────────────────────────────────────────────────────

$opts = getopt('', [
    'arm:', 'bench:', 'rounds:', 'size:', 'iterations:', 'groups:',
    'mem-sizes:', 'mem-workloads:', 'mem-runs:', 'min-ms:', 'mem-min-bytes:',
    'platform:', 'label:', 'toolchain:', 'provenance:',
    'baseline:', 'gate', 'allow-contaminated', 'control-ceiling:', 'strict-hygiene',
    'derive:', 'update-baseline:', 'tier:', 'allow-mixed-commits',
    'out:', 'quiet', 'help', 'stability-csv:',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, "See the header comment of " . __FILE__ . "\n");
    exit(0);
}

$quiet = isset($opts['quiet']);

/** getopt returns a string for one occurrence and an array for several. */
function gate_multi(array $opts, string $key): array
{
    if (!isset($opts[$key])) { return []; }
    return is_array($opts[$key]) ? $opts[$key] : [$opts[$key]];
}

// ── Defaults ────────────────────────────────────────────────────────────────

$bench_script = $opts['bench'] ?? __DIR__ . '/../examples/benchmarks/judy-bench.php';
$rounds       = max(3, (int) ($opts['rounds']     ?? 5));
$size         = max(1, (int) ($opts['size']       ?? 300000));
$iterations   = max(1, (int) ($opts['iterations'] ?? 3));
$mem_runs     = max(1, (int) ($opts['mem-runs']   ?? 3));
$min_ms       = (float) ($opts['min-ms'] ?? 0.5);

/**
 * Smallest structure a memory cell may hold and still be GATED.
 *
 * Peak RSS is quantised to whole pages, and a fixed number of pages of slop is a
 * large fraction of a small structure and a negligible one of a big structure.
 * Measured here across three same-commit runs at n=100k on macOS arm64:
 * `bitset` (~200-400 KB) moved 22%, `int_to_int` (~1.1 MB) moved 33%, while
 * `int_sparse` and `string_to_int` (~5-8 MB) moved 2.7% and 3.8%. #161 saw the
 * same thing on Linux and called it out as "one page rounding, not a
 * regression" (192 KB vs 384 KB at the smallest size).
 *
 * Small cells are therefore still MEASURED and reported — they carry the
 * headline BITSET ratios — but they are not allowed to fail a build, because a
 * threshold wide enough to accommodate them would be too wide to catch anything
 * on the cells that matter.
 */
$mem_min_bytes = (int) ($opts['mem-min-bytes'] ?? 4 * 1024 * 1024);
$out_file     = $opts['out'] ?? 'bench-gate.json';

$groups = array_values(array_filter(array_map('trim', explode(
    ',', $opts['groups'] ?? 'core.int,core.str,api.setops'))));
$mem_sizes = array_values(array_filter(array_map('intval', explode(
    ',', $opts['mem-sizes'] ?? '1000000'))));
$mem_workloads = array_values(array_filter(array_map('trim', explode(
    ',', $opts['mem-workloads'] ?? 'int_to_int,int_sparse,string_to_int,bitset'))));

/**
 * The platform key. Every stored ratio is scoped to one of these, because a
 * ratio is only comparable to a ratio taken with the same libc, compiler family
 * and instruction set — musl's allocator is not glibc's and Judy is
 * allocation-heavy, arm64 lowers the O-series to different instructions than
 * x86-64 does, and neither is a bug.
 */
$platform = $opts['platform'] ?? gate_default_platform();

function gate_default_platform(): string
{
    $arch = strtolower(php_uname('m'));
    $arch = ['amd64' => 'x86_64', 'aarch64' => 'arm64'][$arch] ?? $arch;
    if (PHP_OS_FAMILY === 'Darwin')  { return "macos-$arch"; }
    if (PHP_OS_FAMILY === 'Windows') { return "windows-$arch"; }
    // glibc vs musl changes the allocator under Judy's node churn, so it is
    // part of the platform identity, not a footnote.
    $libc = 'glibc';
    if (is_file('/etc/alpine-release')) {
        $libc = 'musl';
    } elseif (@is_readable('/proc/self/maps')
        && str_contains((string) @file_get_contents('/proc/self/maps'), 'musl')) {
        $libc = 'musl';
    }
    return "linux-$libc-$arch";
}

/**
 * Above this, a timing cell is reported but never gated.
 *
 * A cell whose own reproducibility is worse than 50% cannot resolve any
 * regression worth a tool: a change that large is visible without one, and a
 * "threshold" of 190% in the baseline would falsely suggest coverage. Memory is
 * far more reproducible and gets a much lower ceiling.
 */
const GATE_CELL_FLOOR_CEILING     = 50.0;
const GATE_CELL_FLOOR_CEILING_MEM = 15.0;

/**
 * How much evidence a PER-CELL floor needs before it is allowed to be tighter
 * than the pooled axis floor.
 *
 * A per-cell floor is estimated from that one cell's handful of observations,
 * while the axis floor pools ~50 cells. When what is being estimated is a
 * systematic per-runner offset — which is what cross-runner drift is — the
 * pooled statistic is far better conditioned, and letting a thin per-cell
 * estimate go below it is how the first gated run produced five false
 * regressions against its own baseline.
 */
const GATE_MIN_RUNS_FOR_PER_CELL_FLOORS  = 8;
const GATE_MIN_HOSTS_FOR_PER_CELL_FLOORS = 4;

// ── Statistics local to the gate ────────────────────────────────────────────

/**
 * Log-space median, used wherever ratios are pooled.
 *
 * Ratios are multiplicative: 0.5x and 2.0x are the same magnitude of change in
 * opposite directions, and averaging them arithmetically gives 1.25x, which
 * claims a slowdown that is not there. Everything that combines ratios across
 * cells therefore does it in log space.
 */
function gate_geo_median(array $ratios): float
{
    $logs = [];
    foreach ($ratios as $r) { if ($r > 0) { $logs[] = log($r); } }
    return $logs ? exp(tam_median($logs)) : 1.0;
}

/**
 * The p-quantile of a sample, nearest-rank.
 *
 * Used wherever a "how far do things scatter" number is wanted. Deliberately NOT
 * the maximum: over ~50 benchmark cells the maximum is an extreme-value
 * statistic that lands at 5-7% on a perfectly healthy runner while the median
 * sits under 1%, so a threshold driven by it would be permanently too wide to
 * catch anything. Measured on this project's own CI, C-vs-C rebuild control:
 * median 0.42-0.96%, p90 1.7-3.0%, max 3.4-6.8%.
 */
function gate_quantile(array $xs, float $p): float
{
    if (!$xs) { return 0.0; }
    sort($xs);
    $i = (int) ceil($p * count($xs)) - 1;
    return (float) $xs[max(0, min(count($xs) - 1, $i))];
}

/** Percentage-point movement of $now relative to $then, as a signed percent. */
function gate_drift_pct(float $now, float $then): float
{
    if ($then <= 0.0) { return 0.0; }
    return ($now / $then - 1.0) * 100.0;
}

// ── --derive: offline threshold floors from repeated same-commit runs ───────
//
// This is where the numbers in the baseline's `noise` block come from. Given N
// JSONs produced by this script on the SAME commit, it measures how far each
// cell's ratio moved between runs when the code did not change. That spread IS
// the false-positive rate of any threshold below it.
//
// The recommended floor is the 95th percentile of |drift| across all cells and
// all run pairs, rounded up. Choosing the max would let one pathological cell
// set the threshold for every cell; choosing the median would flag one cell in
// two on a clean run. The chosen quantile is stated in the output and stored in
// the baseline so the decision is auditable rather than folklore.

if (isset($opts['derive'])) {
    $files = array_values(array_filter(array_map('trim', explode(',', $opts['derive']))));
    if (count($files) < 2) {
        fwrite(STDERR, "--derive needs at least two run JSONs from the SAME commit\n");
        exit(2);
    }
    $runs = [];
    foreach ($files as $f) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (!is_array($j) || ($j['metadata']['schema'] ?? null) !== 'judy-bench-gate/1') {
            fwrite(STDERR, "not a bench-gate run JSON: $f\n");
            exit(2);
        }
        $runs[$f] = $j;
    }

    // Every run must be the same platform and the same commit, or the spread
    // being measured is not "the same code twice".
    $plats = array_unique(array_map(fn($r) => $r['metadata']['platform'], $runs));
    if (count($plats) !== 1) {
        fwrite(STDERR, "--derive: runs span several platforms (" . implode(', ', $plats)
            . "); derive one platform at a time\n");
        exit(2);
    }
    // What must be held constant is the CODE, and for the S->C axis the code is
    // libjudy/'s tree. Requiring one commit would be both too strict and too
    // loose: too strict because the runs that matter most come from separate
    // dispatches (different runner VMs, which is the variance the gate actually
    // faces, and a docs-only commit in between changes nothing measurable), and
    // too loose because one commit says nothing about which tree was built.
    $trees = array_unique(array_map(fn($r) => $r['metadata']['libjudy_tree'] ?? '?', $runs));
    if (count($trees) !== 1 || reset($trees) === '?') {
        fwrite(STDERR, "--derive: runs span several libjudy/ trees (" . implode(', ', $trees)
            . "). The S->C axis measures exactly that tree, so a floor derived across\n"
            . "  two of them measures real change and not noise.\n");
        exit(2);
    }
    $commits = array_values(array_unique(array_map(fn($r) => $r['metadata']['commit'] ?? '?', $runs)));
    if (count($commits) !== 1 && !isset($opts['allow-mixed-commits'])) {
        fwrite(STDERR, "--derive: runs span several commits:\n");
        foreach ($commits as $c) { fwrite(STDERR, "    $c\n"); }
        fwrite(STDERR, "  libjudy/ is identical across them, so the S->C axis is comparable, but the\n"
            . "  extension sources may not be and the A->C axis could be measuring a real change.\n"
            . "  Pass --allow-mixed-commits if the differences are docs/CI only. The commits are\n"
            . "  recorded in the output either way.\n");
        exit(2);
    }

    $derived = [
        'platform'      => reset($plats),
        'libjudy_tree'  => reset($trees),
        'commits'       => $commits,
        'mixed_commits' => count($commits) !== 1,
        'runs'          => count($runs),
        'run_files'     => array_keys($runs),
        // Distinct runner instances are what the derivation is really after: the
        // gate compares against a baseline recorded on a DIFFERENT VM on a
        // different day, so a floor derived only from repeats inside one job
        // understates the variance it will actually face.
        'distinct_hosts' => count(array_unique(array_map(
            fn($r) => $r['metadata']['uname'] ?? '?', $runs))),
        'axes'          => [],
    ];
    $axes = [
        'timing.s_over_c' => ['timing', 's_over_c'],
        'timing.a_over_c' => ['timing', 'a_over_c'],
        'memory.a_over_c' => ['memory', 'a_over_c'],
    ];

    foreach ($axes as $axis => [$section, $key]) {
        // Every pairwise between-run drift for every cell.
        $drifts = [];
        $per_cell = [];
        $vals = [];
        foreach ($runs as $f => $r) {
            foreach (($r[$section][$key] ?? []) as $id => $cell) {
                // Cells the gate will never fail on must not inflate the floor
                // it applies to the cells it does fail on. Small memory cells
                // are page-quantised and move tens of percent between identical
                // runs; letting them set the threshold would make it useless.
                if (($cell['gateable'] ?? true) === false) { continue; }
                $vals[$id][$f] = (float) $cell['ratio'];
            }
        }
        foreach ($vals as $id => $byrun) {
            $series = array_values($byrun);
            if (count($series) < 2) { continue; }
            for ($i = 0; $i < count($series); $i++) {
                for ($j = $i + 1; $j < count($series); $j++) {
                    if ($series[$j] <= 0) { continue; }
                    $d = abs(gate_drift_pct($series[$i], $series[$j]));
                    $drifts[] = $d;
                    $per_cell[$id] = max($per_cell[$id] ?? 0.0, $d);
                }
            }
        }
        sort($drifts);
            arsort($per_cell);

        // PER-CELL floors, not one floor per axis.
        //
        // The axis-wide floor is set by its worst cell, and the worst cells here
        // are pathological: measured across two runner instances,
        // `core.string_to_int_adaptive.free` moved 188% while the median cell
        // moved 1.3%. One floor for the axis would therefore have to be ~98% —
        // a gate that cannot catch anything — purely to accommodate a handful of
        // destructor-timing cells whose cost is dominated by allocator return
        // behaviour rather than by anything this project controls.
        //
        // Each cell instead gets a floor derived from ITS OWN cross-run drift.
        // A stable cell gets a tight gate and a noisy one gets a loose gate that
        // will effectively never fire — which is the correct outcome, because a
        // cell that cannot reproduce itself cannot detect a regression. Nothing
        // is hand-excluded; the data decides, and every cell's floor and verdict
        // is written into the baseline where a reader can audit it.
        $ceiling = ($section === 'memory') ? GATE_CELL_FLOOR_CEILING_MEM : GATE_CELL_FLOOR_CEILING;

        // The AXIS floor is a LOWER BOUND on every cell's floor, not a fallback
        // for cells without one — and that is the correction the first gated run
        // forced.
        //
        // The first attempt set each cell's floor from its own worst observed
        // drift x 1.25 and let it go below the axis number. It cried wolf
        // immediately: five S->C cells on glibc were flagged as regressions
        // against a baseline derived from that same code, having moved 2.5-12.8%
        // against per-cell floors of 2-9.5%.
        //
        // The reason is not a bug, it is the estimator. Cross-runner drift is a
        // SYSTEMATIC per-runner offset, not random per-measurement noise: it is
        // consistent within a job and differs between jobs, so repeats inside one
        // job cannot see it and a per-cell maximum over four samples badly
        // understates it. Every one of the five flagged cells moved by less than
        // the axis p95 (14.6%) — the axis statistic, pooled over ~50 cells, had
        // the sample size to see what the per-cell one did not.
        //
        // So a cell's floor may be WIDER than the axis floor, never narrower,
        // until there are enough runs across enough distinct runners for a
        // per-cell estimate to mean anything. That threshold is stated rather
        // than assumed, and the baseline records which regime it was built in.
        $axis_floor = round(max(1.0, ceil(gate_quantile($drifts, 0.95) * 1.25 * 2) / 2), 2);
        $per_cell_trustworthy = count($runs) >= GATE_MIN_RUNS_FOR_PER_CELL_FLOORS
            && $derived['distinct_hosts'] >= GATE_MIN_HOSTS_FOR_PER_CELL_FLOORS;
        $floor_min = $per_cell_trustworthy
            ? (($section === 'memory') ? 1.0 : 2.0)
            : $axis_floor;

        $cell_floors = [];
        foreach ($per_cell as $id => $worst) {
            // x1.5 rather than x1.25: with a handful of samples the observed
            // worst is routinely exceeded by the next run, which is exactly how
            // the first gated run failed.
            $f = max($floor_min, ceil($worst * 1.5 * 2) / 2);
            $cell_floors[$id] = [
                'floor_pct'    => round($f, 2),
                'worst_drift_pct' => round($worst, 3),
                // Above the ceiling the cell is not gated at all. Carrying a
                // 190% "threshold" in the baseline would suggest a gate exists
                // there when nothing that large is a regression anyone needs a
                // tool to notice.
                'gateable'     => $f <= $ceiling,
            ];
        }
        $gateable_n = count(array_filter($cell_floors, fn($c) => $c['gateable']));

        $derived['axes'][$axis] = [
            'per_cell_floors'   => $cell_floors,
            'gateable_cells'    => $gateable_n,
            'ungateable_cells'  => count($cell_floors) - $gateable_n,
            'cell_floor_ceiling_pct' => $ceiling,
            'cell_floor_rule'   => sprintf(
                'each cell: max(%.2f, its own worst cross-run drift x 1.5, rounded up to 0.5pp); '
                . 'above %.1f%% the cell is reported but not gated',
                $floor_min, $ceiling),
            'per_cell_below_axis_allowed' => $per_cell_trustworthy,
            'axis_floor_is_lower_bound'   => !$per_cell_trustworthy,
            'why' => $per_cell_trustworthy
                ? sprintf('%d runs across %d distinct runners is enough for a per-cell estimate',
                    count($runs), $derived['distinct_hosts'])
                : sprintf('only %d runs across %d distinct runners — too few for a per-cell '
                    . 'estimate of a SYSTEMATIC per-runner offset, so the axis floor (%.2f%%, '
                    . 'pooled over %d cells) is the lower bound for every cell',
                    count($runs), $derived['distinct_hosts'], $axis_floor, count($per_cell)),
            'cells'            => count($per_cell),
            'pairwise_samples' => count($drifts),
            'median_drift_pct' => round(gate_quantile($drifts, 0.50), 3),
            'p90_drift_pct'    => round(gate_quantile($drifts, 0.90), 3),
            'p95_drift_pct'    => round(gate_quantile($drifts, 0.95), 3),
            'max_drift_pct'    => round($drifts ? end($drifts) : 0.0, 3),
            // The recommendation. p95 with a small safety factor, floored so a
            // suspiciously quiet derivation cannot produce a hair-trigger gate.
            'recommended_floor_pct' => round(max(
                1.0,
                ceil(gate_quantile($drifts, 0.95) * 1.25 * 2) / 2   // round up to 0.5pp
            ), 2),
            'quantile_used'    => 'p95 x 1.25, rounded up to 0.5pp, floored at 1.0',
            'axis_floor_note'  => 'the axis floor is a FALLBACK for cells the baseline has no '
                                . 'per-cell floor for; gating uses the per-cell floors above',
            'worst_cells'      => array_map(fn($v) => round($v, 3), array_slice($per_cell, 0, 8, true)),
        ];
    }

    fwrite(STDOUT, json_encode($derived, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    if (isset($opts['update-baseline'])) {
        $bl_path = $opts['update-baseline'];
        $bl = is_file($bl_path)
            ? (json_decode((string) file_get_contents($bl_path), true) ?: [])
            : [];
        $bl['schema']    = 'judy-bench-arm-ratios/1';
        $bl['generated'] = date('c');
        $bl['note']      = $bl['note'] ?? gate_baseline_note();

        // Pool the ratios across the derivation runs: the stored baseline is the
        // median of several runs, not one lucky one.
        $plat = reset($plats);
        $entry = [
            'recorded'       => reset($runs)['metadata'],
            'derived_from'   => [
                'runs'           => $derived['runs'],
                'distinct_hosts' => $derived['distinct_hosts'],
                'commits'        => $derived['commits'],
                'libjudy_tree'   => $derived['libjudy_tree'],
            ],
            'noise' => $derived['axes'],
        ];
        foreach ($axes as $axis => [$section, $key]) {
            $pool = [];
            $gateable = [];
            foreach ($runs as $r) {
                foreach (($r[$section][$key] ?? []) as $id => $cell) {
                    // Non-gateable cells are still recorded — they carry the
                    // headline BITSET ratios and a reader wants to see them —
                    // but they are marked so the gate skips them.
                    $pool[$id][] = (float) $cell['ratio'];
                    $gateable[$id] = ($cell['gateable'] ?? true) !== false;
                }
            }
            foreach ($pool as $id => $series) {
                if (count($series) < count($runs)) { continue; }  // not in every run
                $entry[$section][$key][$id] = [
                    'ratio'  => round(gate_geo_median($series), 5),
                    'runs'   => count($series),
                    'spread_pct' => round((max($series) / max(min($series), 1e-9) - 1.0) * 100.0, 3),
                    'gateable' => $gateable[$id] ?? true,
                ];
            }
        }
        $bl['platforms'][$plat] = $entry;
        ksort($bl['platforms']);
        file_put_contents($bl_path,
            json_encode($bl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fwrite(STDERR, "updated $bl_path for platform $plat\n");
    }
    exit(0);
}

function gate_baseline_note(): string
{
    return 'Per-platform WITHIN-RUN arm ratios, the reference the recurring gate '
         . '(.github/workflows/bench-gate.yml, scripts/bench-gate.php) compares against. '
         . 'These are ratios of two arms measured in the same interleaved rounds, NOT '
         . 'absolute times: a ratio survives a change of runner, an absolute time does not. '
         . 'baselines/latest.json is a different instrument (absolute ms for the '
         . 'release-over-release bench-compare.php run) and the two are not interchangeable. '
         . 'Like latest.json, this file moves ONLY in a dedicated commit/PR, never inside a '
         . 'feature PR, and the noise floors are DERIVED (bench-gate.php --derive) from '
         . 'repeated same-commit CI runs rather than chosen.';
}

// ── Arm registration ────────────────────────────────────────────────────────

$arm_specs = gate_multi($opts, 'arm');
if (!$arm_specs) {
    fwrite(STDERR, "at least one --arm ROLE=/path/to/judy.so is required (roles: C, S, B)\n");
    exit(2);
}

/** @var array<string,string[]> role => .so paths */
$by_role = [];
foreach ($arm_specs as $spec) {
    if (!preg_match('/^([CSB])=(.+)$/', $spec, $m)) {
        fwrite(STDERR, "bad --arm '$spec' (expected C=/path, S=/path or B=/path)\n");
        exit(2);
    }
    if (!is_file($m[2])) {
        fwrite(STDERR, "no such object: {$m[2]}\n");
        exit(2);
    }
    $by_role[$m[1]][] = $m[2];
}
if (empty($by_role['C'])) {
    fwrite(STDERR, "--arm C=... is required: it is the tree under test\n");
    exit(2);
}

/** @var array<string,string> handle => role */
$role_of = [];
/** @var array<string,string> handle => .so path */
$so_of = [];
/** @var array<string,string[]> role => handles */
$handles_of = [];
foreach (['S', 'C', 'B'] as $role) {
    foreach ($by_role[$role] ?? [] as $i => $so) {
        $h = $role . ($i + 1);
        tam_register($h, $so);
        $role_of[$h]     = $role;
        $so_of[$h]       = $so;
        $handles_of[$role][] = $h;
    }
}
$all_handles = array_keys($role_of);

// Every arm must map the object we asked for — see tam_verify()'s note on the
// silently-winning pre-installed copy.
$verify = [];
foreach ($all_handles as $h) { $verify[$h] = tam_verify($h, $so_of[$h]); }
tam_verify_array_arm();

// Two builds of the same role are what make the rebuild control possible, and
// two builds that are byte-identical are not two builds.
$hashes = [];
foreach ($so_of as $h => $so) { $hashes[$h] = hash_file('sha256', $so); }
$c_handles = $handles_of['C'] ?? [];
$rebuild_control_available = count($c_handles) >= 2
    && count(array_unique(array_map(fn($h) => $hashes[$h], $c_handles))) >= 2;

if (!$quiet) {
    fwrite(STDERR, "php-judy regression gate — platform $platform\n");
    foreach ($all_handles as $h) {
        fwrite(STDERR, sprintf("  arm %-3s %s  %s\n", $h, substr($hashes[$h], 0, 12), $so_of[$h]));
    }
    if (!$rebuild_control_available) {
        fwrite(STDERR, "  WARNING: fewer than two distinct arm-C builds — the C-vs-C rebuild\n"
            . "           control is unavailable, so this run cannot measure its own noise\n"
            . "           floor and falls back to the stored offline floor alone.\n");
    }
    if (empty($handles_of['S'])) {
        fwrite(STDERR, "  NOTE: no arm S — the S->C axis (libJudy patch erosion) is not measured.\n");
    }
}

// ── Measurement ─────────────────────────────────────────────────────────────

$loads   = [];
$loads[] = tam_load_snapshot('start');
$started = microtime(true);

/** Run one judy-bench.php group under one arm. */
function gate_run_group(string $handle, string $group, int $size, int $iterations, string $bench): array
{
    global $tmp_root;
    $json = "$tmp_root/out-$handle.json";
    $err  = "$tmp_root/err-$handle.txt";
    @unlink($json);

    $cmd = tam_php($handle) . escapeshellarg($bench)
        . ' --group ' . escapeshellarg($group)
        . ' --size ' . $size
        . ' --iterations ' . $iterations
        . ' --json ' . escapeshellarg($json)
        // Last on purpose: PHP's getopt() stops parsing at an option it does
        // not know, so a --memory placed before --json would swallow it in any
        // judy-bench.php that predates the flag.
        . ' --memory off'
        . ' > ' . TAM_DEVNULL . ' 2> ' . escapeshellarg($err);
    exec($cmd, $_, $status);
    $stderr = (string) @file_get_contents($err);
    if (stripos($stderr, 'already loaded') !== false
        || stripos($stderr, 'Unable to load dynamic library') !== false) {
        fwrite(STDERR, "arm $handle: extension loading broke mid-run:\n$stderr\n");
        exit(2);
    }
    if ($status !== 0 || !is_file($json)) {
        fwrite(STDERR, "arm $handle: group $group failed (status $status)\n$stderr\n");
        exit(2);
    }
    $data = json_decode((string) file_get_contents($json), true);
    if (!is_array($data) || !isset($data['benchmarks'])) {
        fwrite(STDERR, "arm $handle: group $group produced no benchmarks\n");
        exit(2);
    }
    return $data['benchmarks'];
}

/** @var array<string,array<string,float[]>> handle => id => ms[] */
$judy_ms = [];
$php_ms  = [];
foreach ($all_handles as $h) { $judy_ms[$h] = []; $php_ms[$h] = []; }

$children = 0;
foreach ($groups as $group) {
    for ($r = 1; $r <= $rounds; $r++) {
        // Every arm is measured once per round, and the traversal order is
        // reversed on even rounds. That is the N-arm generalisation of ABBA:
        // each arm's mean position in the round is the same, so the linear
        // component of drift cancels instead of loading onto whichever arm
        // always ran last. Arms within a round sit seconds apart, never minutes
        // — running whole suites back to back produced a wall of false
        // regressions before (#87).
        $order = ($r % 2 === 1) ? $all_handles : array_reverse($all_handles);
        foreach ($order as $h) {
            foreach (gate_run_group($h, $group, $size, $iterations, $bench_script) as $id => $entry) {
                if (array_key_exists('heap_bytes', $entry)) { continue; }
                if (str_ends_with($id, '.judy')) {
                    $judy_ms[$h][substr($id, 0, -5)][] = (float) $entry['median_ms'];
                } elseif (str_ends_with($id, '.php')) {
                    $php_ms[$h][substr($id, 0, -4)][] = (float) $entry['median_ms'];
                }
            }
            $children++;
        }
        if (!$quiet) { fwrite(STDERR, sprintf("  timing %-12s round %d/%d\r", $group, $r, $rounds)); }
    }
    if (!$quiet) { fwrite(STDERR, sprintf("  timing %-12s done          \n", $group)); }
}
$loads[] = tam_load_snapshot('after-timing');

// ── Memory ──────────────────────────────────────────────────────────────────
//
// One representative build per role. Peak RSS barely varies between two links of
// the same source, so rotating builds here would buy nothing for its cost.

$memory = [];
if ($mem_sizes && $mem_workloads) {
    $mem_arms = ['A' => 'array'];
    foreach (['S', 'C', 'B'] as $role) {
        if (!empty($handles_of[$role])) { $mem_arms[$role] = $handles_of[$role][0]; }
    }
    $floor = [];
    foreach ($mem_arms as $class => $handle) {
        $cell = tam_mem_cell($handle, 'floor', $class === 'A' ? 'array' : 'judy', 0, $mem_runs, __FILE__);
        $children += $mem_runs;
        $floor[$class] = $cell['peak_rss_bytes'] ?? 0;
    }
    foreach ($mem_workloads as $workload) {
        foreach ($mem_sizes as $n) {
            $row = ['workload' => $workload, 'n' => $n, 'arms' => []];
            foreach ($mem_arms as $class => $handle) {
                $cell = tam_mem_cell($handle, $workload, $class === 'A' ? 'array' : 'judy',
                                     $n, $mem_runs, __FILE__);
                $children += $mem_runs;
                if ($cell === null) { $row['arms'][$class] = null; continue; }
                $cell['over_floor_bytes'] = max(0, $cell['peak_rss_bytes'] - $floor[$class]);
                $row['arms'][$class] = $cell;
            }
            $memory[] = $row;
            if (!$quiet) {
                fwrite(STDERR, sprintf("  memory %-14s n=%-9d ok\n", $workload, $n));
            }
        }
    }
    $loads[] = tam_load_snapshot('after-memory');
}

$loads[] = tam_load_snapshot('end');
$wall = microtime(true) - $started;

// ── Analysis ────────────────────────────────────────────────────────────────

/**
 * Paired per-round ratios of two arms.
 *
 * Round r's numerator and denominator were measured seconds apart in the same
 * round, so whatever the machine was doing during round r hits both and divides
 * out. This is the whole reason the gate can run on a shared runner.
 */
function gate_pairs(array $num, array $den): array
{
    $out = [];
    $n = min(count($num), count($den));
    for ($r = 0; $r < $n; $r++) {
        if ($den[$r] > 0.0) { $out[] = $num[$r] / $den[$r]; }
    }
    return $out;
}

/** Pool the per-round ratios of every (numerator handle, denominator handle) pair. */
function gate_role_pairs(array $ms, array $num_handles, array $den_handles, string $id): array
{
    $out = [];
    foreach ($num_handles as $nh) {
        foreach ($den_handles as $dh) {
            if ($nh === $dh) { continue; }
            $out = array_merge($out, gate_pairs($ms[$nh][$id] ?? [], $ms[$dh][$id] ?? []));
        }
    }
    return $out;
}

// ---- control 1: PHP-only rows, pooled across every arm ---------------------
$control_php = [];
foreach ($php_ms[$c_handles[0]] ?? [] as $id => $_series) {
    if (!in_array($id, TAM_ARM_A_ROWS, true)) { continue; }
    $pairs = gate_role_pairs($php_ms, $c_handles, array_diff($all_handles, $c_handles), $id);
    if (count($pairs) < 3) { continue; }
    $control_php[] = tam_median($pairs);
}
$control_php_ratio = $control_php ? gate_geo_median($control_php) : 1.0;
$control_php_dev = array_map(fn($r) => abs($r - 1.0) * 100.0, $control_php);
$control_php_scatter = gate_quantile($control_php_dev, 0.90);
$control_php_max     = $control_php_dev ? max($control_php_dev) : 0.0;

// ---- control 2: C1 vs C2 — the memory-access-matched one -------------------
$control_cc_rows = [];
$control_cc = [];
if ($rebuild_control_available) {
    foreach ($judy_ms[$c_handles[0]] as $id => $series) {
        $pairs = gate_pairs($judy_ms[$c_handles[1]][$id] ?? [], $series);
        if (count($pairs) < 3) { continue; }
        if (tam_median($series) < $min_ms) { continue; }
        $m = tam_median($pairs);
        $control_cc[] = $m;
        $control_cc_rows[$id] = ['ratio' => round($m, 5),
                                 'delta_pct' => round(($m - 1.0) * 100.0, 3)];
    }
}
$control_cc_ratio = $control_cc ? gate_geo_median($control_cc) : 1.0;
// The scatter that matters is the WIDTH of the control's distribution, not its
// centre: a rebuild control centred at 1.000 whose cells routinely reach +/-3%
// is telling you that a 3% cell movement means nothing today.
//
// p90 rather than max, for the reason gate_quantile() documents: over ~50 cells
// the max is an extreme-value statistic that reads 5-7% on a healthy runner and
// would pin the threshold there forever.
$control_cc_dev = array_map(fn($r) => abs($r - 1.0) * 100.0, $control_cc);
$control_cc_scatter = $control_cc ? gate_quantile($control_cc_dev, 0.90) : null;
$control_cc_max     = $control_cc ? max($control_cc_dev) : null;

// ---- hygiene, and what actually decides whether this run may assert ---------
//
// Load and co-tenancy are still SAMPLED and reported — they are what a human
// needs in order to interpret a surprising result. They do not, however, decide
// the run's fate here, and that is a deliberate departure from
// bench-threearm.php's dedicated-host rule.
//
// Two reasons. First, a CI runner is a shared box by definition: GitHub's
// arm64 macOS runners have 3 vCPUs, so `load < N/2` means load < 1.5 and
// essentially every run trips it. A gate that is red on one platform every
// single week is a gate somebody turns off.
//
// Second, and more important: the failure mode the load rule exists to catch
// CANNOT hurt a ratio. A co-resident tenant stealing last-level cache and
// memory bandwidth slows both arms of every pair — they are measured seconds
// apart in the same round — so it divides out. That is exactly what destroyed
// the absolute-number measurement on the dedicated host (2.2x on an untouched
// arm) and exactly what a paired ratio is immune to. bench-threearm.php reports
// absolute milliseconds and therefore keeps the strict gate; this driver
// reports ratios and does not need it. `--strict-hygiene` restores the old
// behaviour for anyone who wants it.
//
// What DOES decide the run's fate is a measurement rather than a proxy: the
// C-vs-C rebuild control is two builds of identical source, so its scatter is a
// direct reading of how much a cell can move today for no reason at all. When
// that exceeds the ceiling, the run demonstrably cannot resolve anything worth
// catching, and it says so instead of pretending.
$hygiene_failed = false;
$foreign_seen   = false;
foreach ($loads as $l) {
    if ($l['over']) { $hygiene_failed = true; }
    if (!empty($l['foreign_busy'])) { $hygiene_failed = true; $foreign_seen = true; }
}

$control_ceiling = (float) ($opts['control-ceiling'] ?? 15.0);
$inconclusive_reason = null;
if ($control_cc_scatter !== null && $control_cc_scatter > $control_ceiling) {
    $inconclusive_reason = sprintf(
        'the C-vs-C rebuild control scattered %.2f%% (p90), above the %.1f%% ceiling — two '
        . 'builds of identical source disagreed by more than any regression worth catching, '
        . 'so this run cannot resolve one',
        $control_cc_scatter, $control_ceiling);
} elseif ($control_cc_scatter === null && $hygiene_failed) {
    $inconclusive_reason = 'no C-vs-C rebuild control was available to measure this run\'s '
        . 'noise, and host hygiene failed';
} elseif (isset($opts['strict-hygiene']) && $hygiene_failed) {
    $inconclusive_reason = 'host hygiene failed and --strict-hygiene was given';
}
$contaminated = $inconclusive_reason !== null;

// ── Build this run's axes ───────────────────────────────────────────────────

$s_handles = $handles_of['S'] ?? [];
$b_handles = $handles_of['B'] ?? [];

/**
 * One axis cell: the paired-ratio median, its bootstrap CI, and the raw series.
 * Timing axes are re-centred by the PHP-only control so pure runner movement
 * divides out while a genuine library-wide shift survives.
 */
function gate_cell(array $pairs, float $recentre = 1.0): ?array
{
    if (count($pairs) < 3) { return null; }
    $adj = $recentre != 1.0 ? array_map(fn($p) => $p / $recentre, $pairs) : $pairs;
    $ratio = tam_median($adj);
    $ci    = tam_median_ci($adj);
    return [
        'ratio'        => round($ratio, 5),
        'ci'           => [round($ci[0], 5), round($ci[1], 5)],
        'delta_pct'    => round(($ratio - 1.0) * 100.0, 3),
        'n'            => count($adj),
    ];
}

$timing = ['s_over_c' => [], 'a_over_c' => [], 'b_over_c' => []];

// S -> C: the libJudy patch axis. Numerator C, denominator S, so a ratio below
// 1 means the bundled tree is faster than pristine — the expected direction.
if ($s_handles) {
    foreach ($judy_ms[$c_handles[0]] as $id => $_s) {
        $pairs = gate_role_pairs($judy_ms, $c_handles, $s_handles, $id);
        $med_c = tam_median($judy_ms[$c_handles[0]][$id] ?? []);
        if ($med_c < $min_ms) { continue; }
        $cell = gate_cell($pairs, $control_php_ratio);
        if ($cell !== null) { $timing['s_over_c'][$id] = $cell; }
    }
}

// B -> C: reported, never gated (the distro package moves independently of us).
if ($b_handles) {
    foreach ($judy_ms[$c_handles[0]] as $id => $_s) {
        $pairs = gate_role_pairs($judy_ms, $c_handles, $b_handles, $id);
        if (tam_median($judy_ms[$c_handles[0]][$id] ?? []) < $min_ms) { continue; }
        $cell = gate_cell($pairs, $control_php_ratio);
        if ($cell !== null) { $timing['b_over_c'][$id] = $cell; }
    }
}

// A -> C: judy against a PHP array, paired INSIDE one process. Only rows whose
// .php arm is a genuine PHP array qualify; the rest are a PHP loop over a Judy
// instance, which is a real but different question. No re-centring: both members
// of the pair come from the same child, microseconds apart.
foreach ($judy_ms[$c_handles[0]] as $id => $_s) {
    if (!in_array($id, TAM_ARM_A_ROWS, true)) { continue; }
    $pairs = [];
    foreach ($c_handles as $ch) {
        $pairs = array_merge($pairs, gate_pairs($judy_ms[$ch][$id] ?? [], $php_ms[$ch][$id] ?? []));
    }
    if (tam_median($judy_ms[$c_handles[0]][$id] ?? []) < $min_ms) { continue; }
    $cell = gate_cell($pairs);
    if ($cell !== null) { $timing['a_over_c'][$id] = $cell; }
}

// Memory: array bytes over judy bytes, so ABOVE 1 is a php-judy win and the
// adverse direction is DOWNWARD — the opposite of the timing axes.
$mem_axes = ['a_over_c' => [], 's_over_c' => []];
foreach ($memory as $row) {
    $key = $row['workload'] . '@' . $row['n'];
    $a = $row['arms']['A']['over_floor_bytes'] ?? null;
    $c = $row['arms']['C']['over_floor_bytes'] ?? null;
    $s = $row['arms']['S']['over_floor_bytes'] ?? null;
    // Gateable only when BOTH arms hold enough pages for the ratio to mean
    // something; see $mem_min_bytes.
    $gateable = $c !== null && $c >= $mem_min_bytes;
    if ($a !== null && $c) {
        $mem_axes['a_over_c'][$key] = [
            'ratio' => round($a / $c, 5), 'bytes' => ['A' => $a, 'C' => $c],
            'gateable' => $gateable && $a >= $mem_min_bytes,
            'not_gateable_reason' => ($gateable && $a >= $mem_min_bytes) ? null
                : sprintf('below the %s gating floor — peak RSS is page-quantised and a '
                    . 'small structure moves several percent between identical runs',
                    tam_fmt_bytes($mem_min_bytes)),
        ];
    }
    if ($s !== null && $c) {
        $mem_axes['s_over_c'][$key] = [
            'ratio' => round($s / $c, 5), 'bytes' => ['S' => $s, 'C' => $c],
            'gateable' => $gateable && $s >= $mem_min_bytes,
        ];
    }
}

// ── Gate ────────────────────────────────────────────────────────────────────

/**
 * Axis definitions: which direction is adverse, and how tight the offline floor
 * is when the baseline does not carry a derived one.
 *
 * The memory floor is far tighter than the timing floors because peak RSS of a
 * deterministic build is very nearly deterministic — the only jitter is
 * allocator and page-granularity rounding — while a timing ratio on a shared
 * runner carries scheduler and cache-occupancy noise no amount of pairing fully
 * removes.
 */
const GATE_AXES = [
    'timing.s_over_c' => ['adverse' => 'up',   'fallback_floor_pct' => 8.0,
        'what' => 'the vendored libJudy patches, against the pristine-static arm'],
    'timing.a_over_c' => ['adverse' => 'up',   'fallback_floor_pct' => 8.0,
        'what' => 'php-judy against a PHP native array (extension + library)'],
    'memory.a_over_c' => ['adverse' => 'down', 'fallback_floor_pct' => 3.0,
        'what' => 'memory advantage over a PHP native array'],
];

$baseline = null;
$baseline_platform = null;
if (isset($opts['baseline'])) {
    // An ABSENT baseline is the bootstrap case, not an error: the first run on a
    // new platform has nothing to compare against and its job is to produce the
    // numbers the baseline will later be built from. A baseline that exists but
    // cannot be parsed is a different thing and does fail, because silently
    // measuring nothing when the reviewer believes a gate is running is worse
    // than not having the gate.
    if (!is_file($opts['baseline'])) {
        fwrite(STDERR, "no baseline file at {$opts['baseline']} — measuring without gating\n");
    } else {
        $baseline = json_decode((string) @file_get_contents($opts['baseline']), true);
        if (!is_array($baseline) || ($baseline['schema'] ?? null) !== 'judy-bench-arm-ratios/1') {
            fwrite(STDERR, "unusable baseline file: {$opts['baseline']}\n");
            exit(2);
        }
        $baseline_platform = $baseline['platforms'][$platform] ?? null;
    }
}

$findings   = [];
$thresholds = [];
$gate_status = 'not-gated';

if ($baseline_platform !== null) {
    $current = ['timing' => $timing, 'memory' => $mem_axes];

    foreach (GATE_AXES as $axis => $def) {
        [$section, $key] = explode('.', $axis);

        // The threshold: the larger of the offline floor derived from repeated
        // same-commit runs and THIS run's own C-vs-C control scatter. A bad day
        // on the runner widens the gate rather than fabricating regressions.
        $cell_floors = $baseline_platform['noise'][$axis]['per_cell_floors'] ?? [];
        $offline = $baseline_platform['noise'][$axis]['recommended_floor_pct']
            ?? $def['fallback_floor_pct'];
        $measured = ($section === 'timing') ? $control_cc_scatter : null;
        $threshold = max((float) $offline, (float) ($measured ?? 0.0));
        $thresholds[$axis] = [
            'applied_pct'          => round($threshold, 3),
            'offline_floor_pct'    => round((float) $offline, 3),
            'offline_floor_source' => isset($baseline_platform['noise'][$axis]['recommended_floor_pct'])
                ? 'derived from repeated same-commit runs (baseline `noise` block)'
                : 'FALLBACK CONSTANT — no derived floor stored for this platform yet',
            'run_control_scatter_pct' => $measured === null ? null : round($measured, 3),
            'governed_by'          => ($measured !== null && $measured > $offline)
                ? 'this run\'s C-vs-C rebuild control (the runner was noisy)'
                : 'the offline derived floor',
            'adverse_direction'    => $def['adverse'],
            'per_cell_floors'      => count($cell_floors),
            'cells_not_gated'      => count(array_filter($cell_floors,
                                        fn($c) => ($c['gateable'] ?? true) === false)),
            'note'                 => 'applied_pct is the AXIS fallback. Gating uses each cell\'s '
                                    . 'own derived floor where the baseline has one, because one '
                                    . 'floor per axis would be set by its worst cell.',
        ];

        foreach ($current[$section][$key] ?? [] as $id => $cell) {
            $base = $baseline_platform[$section][$key][$id]['ratio'] ?? null;
            if ($base === null || $base <= 0) { continue; }
            // Reported, never gated: see $mem_min_bytes.
            if (($cell['gateable'] ?? true) === false) { continue; }

            // This cell's own derived floor governs where one exists. The
            // axis-wide number is only a fallback for a cell the baseline has
            // not seen before.
            $cf = $cell_floors[$id] ?? null;
            if ($cf !== null && ($cf['gateable'] ?? true) === false) { continue; }
            $cell_threshold = max((float) ($cf['floor_pct'] ?? $offline), (float) ($measured ?? 0.0));

            $drift = gate_drift_pct((float) $cell['ratio'], (float) $base);
            // Memory cells carry no CI (RSS is a median of a few deterministic
            // children); treat the point estimate as its own interval there.
            $ci = $cell['ci'] ?? [$cell['ratio'], $cell['ratio']];
            $drift_lo = gate_drift_pct((float) $ci[0], (float) $base);
            $drift_hi = gate_drift_pct((float) $ci[1], (float) $base);

            // The WHOLE interval must clear the threshold in the adverse
            // direction. A point estimate past it with a straddling interval is
            // movement worth reporting, never a regression worth failing on.
            $regressed = $def['adverse'] === 'up'
                ? ($drift_lo > $cell_threshold)
                : ($drift_hi < -$cell_threshold);
            $improved = $def['adverse'] === 'up'
                ? ($drift_hi < -$cell_threshold)
                : ($drift_lo > $cell_threshold);

            $status = $regressed ? 'REGRESSION' : ($improved ? 'improved' : 'ok');
            if ($status === 'REGRESSION' && $contaminated) {
                $status = 'suppressed';
            }
            if ($status !== 'ok') {
                $findings[] = [
                    'axis'      => $axis,
                    'what'      => $def['what'],
                    'platform'  => $platform,
                    'cell'      => $id,
                    'status'    => $status,
                    'baseline_ratio' => round((float) $base, 5),
                    'current_ratio'  => $cell['ratio'],
                    'drift_pct'      => round($drift, 3),
                    'drift_ci_pct'   => [round($drift_lo, 3), round($drift_hi, 3)],
                    'threshold_pct'  => round($cell_threshold, 3),
                    'threshold_source' => $cf === null
                        ? 'axis fallback — the baseline has no per-cell floor for this cell yet'
                        : sprintf('this cell\'s own cross-run drift (worst %.2f%%)', $cf['worst_drift_pct']),
                ];
            }
        }
    }

    $regressions = array_values(array_filter($findings, fn($f) => $f['status'] === 'REGRESSION'));
    $suppressed  = array_values(array_filter($findings, fn($f) => $f['status'] === 'suppressed'));
    $gate_status = $regressions ? 'FAIL' : ($contaminated ? 'INCONCLUSIVE' : 'PASS');
} elseif ($baseline !== null) {
    $gate_status = 'no-baseline-for-platform';
    $regressions = [];
    $suppressed  = [];
} else {
    $regressions = [];
    $suppressed  = [];
}

// ── Output ──────────────────────────────────────────────────────────────────

$git = 'git -C ' . escapeshellarg(__DIR__) . ' ';
$commit       = trim((string) @shell_exec($git . 'rev-parse HEAD 2> ' . TAM_DEVNULL));
$libjudy_tree = trim((string) @shell_exec($git . 'rev-parse HEAD:libjudy 2> ' . TAM_DEVNULL));

$result = [
    'metadata' => [
        'schema'      => 'judy-bench-gate/1',
        'platform'    => $platform,
        'label'       => $opts['label'] ?? $platform,
        'tier'        => $opts['tier'] ?? 'ci-relative',
        'commit'      => $commit ?: null,
        // The tree that actually determines the S->C axis. Recorded separately
        // from the commit so a floor can be derived across docs/CI-only commits
        // without pretending the measured code changed.
        'libjudy_tree'=> $libjudy_tree ?: null,
        'date'        => date('c'),
        'php_version' => PHP_VERSION,
        'uname'       => php_uname(),
        'toolchain'   => $opts['toolchain'] ?? null,
        'provenance'  => $opts['provenance'] ?? null,
        'arms'        => array_map(fn($h) => [
            'handle' => $h, 'role' => $role_of[$h],
            'so' => $so_of[$h], 'sha256' => $hashes[$h],
        ], $all_handles),
        'rebuild_control_available' => $rebuild_control_available,
        'size'        => $size,
        'rounds'      => $rounds,
        'iterations'  => $iterations,
        'groups'      => $groups,
        'mem_sizes'   => $mem_sizes,
        'mem_workloads' => $mem_workloads,
        'mem_min_gateable_bytes' => $mem_min_bytes,
        'children'    => $children,
        'wall_seconds'=> round($wall, 1),
    ],
    'hygiene' => [
        'snapshots'      => $loads,
        'failed'         => $hygiene_failed,
        'foreign_tenant' => $foreign_seen,
        'contaminated'   => $contaminated,
        'inconclusive_reason' => $inconclusive_reason,
        'control_ceiling_pct' => $control_ceiling,
        'decides_the_run'=> false,
        'note'           => 'Sampled and reported for interpretation, but it does NOT decide this '
                          . 'run\'s fate. LLC/bandwidth contention slows both arms of a pair equally '
                          . '(they are measured seconds apart in the same round) and divides out of '
                          . 'a ratio — it is what ruined the dedicated host\'s ABSOLUTE numbers, not '
                          . 'something a paired ratio is exposed to. The C-vs-C rebuild control\'s '
                          . 'scatter decides instead, because it is a direct measurement of how far '
                          . 'a cell can move today for no reason. --strict-hygiene restores the '
                          . 'dedicated-host rule.',
    ],
    'controls' => [
        'php_only' => [
            'ratio'          => round($control_php_ratio, 5),
            'scatter_pct'    => round($control_php_scatter, 3),
            'scatter_stat'   => 'p90 of |per-cell deviation|',
            'max_dev_pct'    => round($control_php_max, 3),
            'rows'           => count($control_php),
            'measures'    => 'runner drift (interpreter speed, CPU frequency, process startup)',
            'blind_to'    => 'LLC and memory-bandwidth contention — PHP array ops are neither '
                           . 'pointer-chasing nor DRAM-bound, so this control stayed flat at +0.36% '
                           . 'while a co-resident tenant moved an untouched Judy arm 2.2x',
        ],
        'cc_rebuild' => [
            'available'   => $rebuild_control_available,
            'ratio'       => round($control_cc_ratio, 5),
            'scatter_pct'  => $control_cc_scatter === null ? null : round($control_cc_scatter, 3),
            'scatter_stat' => 'p90 of |per-cell deviation|',
            'max_dev_pct'  => $control_cc_max === null ? null : round($control_cc_max, 3),
            'rows'         => count($control_cc_rows),
            'cells'       => $control_cc_rows,
            'measures'    => 'the apparatus itself: two independently linked builds of IDENTICAL '
                           . 'source, so every cell should read null. Shares Judy\'s memory-access '
                           . 'character (pointer-chasing, DRAM-bound) and therefore CAN see the '
                           . 'contention the PHP-array control cannot.',
            'role'        => 'its scatter is this run\'s empirical noise floor and raises the gate '
                           . 'threshold when it exceeds the offline-derived one',
        ],
    ],
    'timing'     => $timing,
    'memory'     => $mem_axes,
    'memory_raw' => $memory,
    'gate' => [
        'status'      => $gate_status,
        'baseline'    => $opts['baseline'] ?? null,
        'thresholds'  => $thresholds,
        'findings'    => $findings,
        'regressions' => count($regressions),
        'suppressed'  => count($suppressed),
    ],
    'verify' => $verify,
];

file_put_contents($out_file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// ---- the shared baseline-stability contract --------------------------------
//
// tools/bench-stability.py is this repo's contention detector: it takes an
// UNTOUCHED baseline arm as a canary and fails when that arm's own absolute
// timings move between trials, which is strictly stronger than the loadavg
// heuristic that both of the campaigns that once corrupted each other passed.
// Rather than reimplement that idea, this driver emits the CSV contract the
// tool already reads (`arm,seed,corpus,n,trial,kernel,ns_per_op,hits`, with the
// canary arm named `pre` and the kernel named `serial`) so the SAME tool can be
// run over a gate run unchanged.
//
// Arm S is the canary: it is reconstructed from a pinned historical commit, so
// no change in this repository can move it. Rounds are trials.
//
// Note what the two instruments answer, because they are not the same question.
// bench-stability.py asks "were the ABSOLUTE times stable?". This gate's
// verdicts never depend on that — both members of every pair are measured
// seconds apart in the same round, so a drift that moves them together divides
// out. A stability failure here therefore means "absolute numbers from this run
// are not quotable", not "the gate's ratios are wrong". Both statements are
// worth having, which is why the CSV is emitted rather than the check being
// declared inapplicable.
if (isset($opts['stability-csv'])) {
    $rows = ["arm,seed,corpus,n,trial,kernel,ns_per_op,hits"];
    foreach ($all_handles as $h) {
        // `pre` is the tool's reserved name for the untouched baseline.
        $arm_name = $role_of[$h] === 'S' ? 'pre' : strtolower($role_of[$h]);
        foreach ($judy_ms[$h] as $id => $series) {
            foreach ($series as $trial => $ms) {
                // ns per element, so cells of different cost are comparable.
                $rows[] = sprintf('%s,%s,%s,%d,%d,serial,%.4f,%d',
                    $arm_name, $h, $id, $size, $trial + 1, $ms * 1e6 / max(1, $size), $size);
            }
        }
    }
    file_put_contents($opts['stability-csv'], implode("\n", $rows) . "\n");
}

if (!$quiet) {
    $line = str_repeat('-', 92);
    echo "\n$line\nphp-judy regression gate — $platform\n$line\n";
    printf("  PHP %s, %s\n", PHP_VERSION, php_uname('s') . ' ' . php_uname('m'));
    printf("  %d rounds x %d groups, size %d, %d children, %.0fs wall\n",
        $rounds, count($groups), $size, $children, $wall);
    printf("  confidence tier: %s\n", $result['metadata']['tier']);

    echo "\n  host hygiene\n";
    foreach ($loads as $l) {
        printf("    %-14s load1=%-6s cpus=%-4s threshold=%-5s foreign=%5.1f%% %s\n",
            $l['phase'], $l['load1'] ?? '?', $l['cpus'] ?? '?',
            $l['threshold'] === null ? '?' : sprintf('%.1f', $l['threshold']),
            $l['foreign_cpu_pct'],
            $l['over'] ? '*** LOAD OVER THRESHOLD ***'
                       : (!empty($l['foreign_busy']) ? '*** FOREIGN TENANT ***' : 'ok'));
    }

    printf("\n  control php-only    : %+.2f%% (scatter %.2f%%) over %d rows — sees runner drift only\n",
        ($control_php_ratio - 1.0) * 100.0, $control_php_scatter, count($control_php));
    if ($rebuild_control_available) {
        printf("  control C-vs-C      : %+.2f%% (scatter %.2f%%) over %d cells — sees LLC/bandwidth contention\n",
            ($control_cc_ratio - 1.0) * 100.0, $control_cc_scatter, count($control_cc_rows));
    } else {
        echo "  control C-vs-C      : UNAVAILABLE (needs two distinct arm-C builds)\n";
    }

    foreach (['s_over_c' => 'S -> C  (libJudy patches only)',
              'a_over_c' => 'A -> C  (php-judy over PHP array)',
              'b_over_c' => 'B -> C  (system libJudy — reported, never gated)'] as $k => $title) {
        if (empty($timing[$k])) { continue; }
        echo "\n$line\n  $title\n$line\n";
        $rows = $timing[$k];
        uasort($rows, fn($x, $y) => $x['ratio'] <=> $y['ratio']);
        printf("  %-46s %9s %-20s\n", 'benchmark', 'ratio', 'CI');
        foreach (array_slice($rows, 0, 12, true) as $id => $c) {
            printf("  %-46s %9.4f [%7.4f,%7.4f]\n", $id, $c['ratio'], $c['ci'][0], $c['ci'][1]);
        }
        if (count($rows) > 12) { printf("  ... %d more cells in the JSON\n", count($rows) - 12); }
    }

    if ($mem_axes['a_over_c']) {
        echo "\n$line\n  memory — PHP array bytes / php-judy bytes (above 1.00 is a php-judy win)\n$line\n";
        foreach ($mem_axes['a_over_c'] as $k => $c) {
            printf("  %-32s %8.2fx  %s\n", $k, $c['ratio'],
                $c['gateable'] ? '' : '(reported, not gated — below the size floor)');
        }
    }

    echo "\n$line\n  GATE: $gate_status\n$line\n";
    if ($inconclusive_reason !== null) {
        echo "  INCONCLUSIVE: $inconclusive_reason\n";
    }
    if ($hygiene_failed) {
        echo "  note: host hygiene sampled over threshold. Reported, not enforced — a paired\n"
           . "        ratio is immune to the contention the load rule exists to catch.\n";
    }
    foreach ($thresholds as $axis => $t) {
        printf("  %-18s threshold %5.2f%%  (offline floor %.2f%%, run control %s) — %s\n",
            $axis, $t['applied_pct'], $t['offline_floor_pct'],
            $t['run_control_scatter_pct'] === null ? 'n/a'
                : sprintf('%.2f%%', $t['run_control_scatter_pct']),
            $t['governed_by']);
    }
    if (!$findings) {
        echo "  no cell moved past its threshold in either direction.\n";
    }
    foreach ($findings as $f) {
        printf("  [%s] %s :: %s\n      baseline %.4f -> %.4f  (%+.2f%% [%+.2f,%+.2f], threshold %.2f%%)\n",
            $f['status'], $f['platform'] . ' / ' . $f['axis'], $f['cell'],
            $f['baseline_ratio'], $f['current_ratio'],
            $f['drift_pct'], $f['drift_ci_pct'][0], $f['drift_ci_pct'][1], $f['threshold_pct']);
    }
    echo "\nWrote $out_file\n";
}

if (!isset($opts['gate'])) { exit(0); }
if ($gate_status === 'FAIL') { exit(1); }
if ($gate_status === 'INCONCLUSIVE' && !isset($opts['allow-contaminated'])) { exit(3); }
exit(0);
