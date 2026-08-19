<?php
/**
 * Shared measurement machinery for php-judy's benchmark drivers.
 *
 * Two drivers consume this file and they have different jobs:
 *
 *   scripts/bench-threearm.php  a deep, one-off comparative STUDY — arm A (PHP
 *                               array) against a reference judy build against
 *                               the bundled one, with the peak-RSS memory
 *                               matrix and the full per-cell table. Run on a
 *                               quiet dedicated host when a publishable
 *                               absolute number is wanted.
 *   scripts/bench-gate.php      a recurring cross-platform REGRESSION GATE.
 *                               Runs on shared CI runners, compares this run's
 *                               WITHIN-RUN arm ratios against a stored
 *                               baseline, and fails when a ratio has moved.
 *
 * Everything here was written for the first driver and hardened by things that
 * went wrong in this project. It lives in one file so the second driver
 * inherits the hardening instead of reimplementing an approximation of it:
 *
 *   - `tam_cpu_count()` counts /proc/cpuinfo siblings and honours a cpuset
 *     before it will trust `nproc`, and returns 0 rather than 1 when it cannot
 *     tell. A slim container image without `nproc` used to fall back to 1 core,
 *     which drops the hygiene threshold to 0.5 and marks a perfectly idle
 *     24-core host contaminated — suppressing every verdict in the run.
 *   - `tam_load_snapshot()` samples load AND foreign CPU at phase boundaries,
 *     when none of the driver's own children are running, so anything busy at
 *     that instant is by construction somebody else's work. Load average alone
 *     is necessary but not sufficient: two campaigns once ran concurrently on a
 *     24-core host, each individually passing `load < N/2` at load ~2, and
 *     corrupted each other anyway — a co-resident memory-bound benchmark
 *     contends for last-level cache and memory bandwidth wherever the scheduler
 *     puts it. The damage was 2.2x on an untouched baseline arm, and the
 *     PHP-array drift control read +0.36% throughout.
 *   - `tam_verify()` proves via /proc/self/maps that the arm mapped the .so the
 *     caller asked for. The project's own bench image enables judy in conf.d,
 *     which makes `-d extension=` a silent no-op ("Module already loaded"): the
 *     stale in-image copy wins and `judy_version()` cannot reveal it, because
 *     both copies report the same version string. Only the mapped-path check
 *     distinguishes them. Arms are therefore selected with PHP_INI_SCAN_DIR
 *     pointing at a directory holding exactly one judy.ini, never with
 *     `-d extension=`.
 *   - `tam_verdict()` requires the WHOLE bootstrap CI to clear the claim floor
 *     before a cell may assert a direction. A point estimate past the floor
 *     with a straddling CI is null, not a win.
 *   - `TAM_ARM_A_ROWS` is the list of judy-bench.php rows whose `.php` arm is a
 *     genuine PHP array. The rest build into and iterate over a *Judy* instance,
 *     so they execute libJudy and must never serve as arm A or as the drift
 *     control — using them as the control would subtract the very effect a run
 *     exists to measure.
 *
 * A driver must require this file at TOP-LEVEL scope: the shutdown handler that
 * cleans up the per-arm ini directories closes over `$tmp_root` there.
 */

// The per-run scratch directory every arm's ini lives under. Established here
// rather than by each driver so the shutdown cleanup can close over it.
$tmp_root = sys_get_temp_dir() . '/judy-bench-arms-' . getmypid();
$arm_ini  = [];

/**
 * The null device, spelled for whichever shell exec() will use.
 *
 * exec()/shell_exec() go through cmd.exe on Windows, where `/dev/null` is an
 * ordinary (invalid) path and the redirect fails instead of discarding.
 */
const TAM_DEVNULL = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

// ── Internal child mode: peak-RSS memory measurement ────────────────────────
//
// A driver calls tam_mem_child_main($argv) as its FIRST statement, before its
// own CLI parsing, because tam_mem_cell() re-execs the driver to measure memory
// in a clean process. `ru_maxrss` is bytes on macOS and kilobytes on Linux.

function tam_peak_rss(): int
{
    $ru = getrusage();
    return (int) $ru['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024);
}

/**
 * Child entry point. Returns immediately when `--mem-child` is absent; builds
 * one structure, prints its measurements as JSON and exits when it is present.
 */
function tam_mem_child_main(array $argv): void
{
    if (!in_array('--mem-child', $argv, true)) { return; }
        $mi        = array_search('--mem-child', $argv, true);
        $workload  = $argv[$mi + 1] ?? 'floor';
        $impl      = $argv[$mi + 2] ?? 'array';   // 'array' | 'judy'
        $n         = (int) ($argv[$mi + 3] ?? 0);

        $indexBytes = null;
        $heapBytes  = null;
        $count      = 0;
        $checksum   = 0;

        $before = memory_get_usage();

        if ($workload === 'floor') {
            // Nothing built. Establishes the per-arm process floor: interpreter +
            // whichever extension is loaded, with no data structure at all.
            $store = null;
        } elseif ($impl === 'array') {
            $store = [];
            switch ($workload) {
                case 'int_to_int':
                    for ($i = 0; $i < $n; $i++) { $store[$i] = $i * 3; }
                    break;
                case 'int_sparse':
                    // Sparse int keys: the regime Judy is built for and the one a
                    // PHP array handles worst (a packed array cannot represent it,
                    // so it falls back to a full hash table).
                    for ($i = 0; $i < $n; $i++) { $store[$i * 4099] = $i; }
                    break;
                case 'int_to_mixed':
                    for ($i = 0; $i < $n; $i++) { $store[$i] = ($i & 1) ? (string) $i : $i; }
                    break;
                case 'string_to_int':
                    for ($i = 0; $i < $n; $i++) { $store['key:' . $i] = $i; }
                    break;
                case 'bitset':
                    for ($i = 0; $i < $n; $i++) { $store[$i * 7] = true; }
                    break;
                default:
                    fwrite(STDERR, "unknown workload $workload\n");
                    exit(2);
            }
            $count = count($store);
        } else {
            if (!extension_loaded('judy')) {
                fwrite(STDERR, "judy extension not loaded in memory child\n");
                exit(2);
            }
            switch ($workload) {
                case 'int_to_int':
                    $store = new Judy(Judy::INT_TO_INT);
                    for ($i = 0; $i < $n; $i++) { $store[$i] = $i * 3; }
                    break;
                case 'int_sparse':
                    $store = new Judy(Judy::INT_TO_INT);
                    for ($i = 0; $i < $n; $i++) { $store[$i * 4099] = $i; }
                    break;
                case 'int_to_mixed':
                    $store = new Judy(Judy::INT_TO_MIXED);
                    for ($i = 0; $i < $n; $i++) { $store[$i] = ($i & 1) ? (string) $i : $i; }
                    break;
                case 'string_to_int':
                    $store = new Judy(Judy::STRING_TO_INT);
                    for ($i = 0; $i < $n; $i++) { $store['key:' . $i] = $i; }
                    break;
                case 'bitset':
                    $store = new Judy(Judy::BITSET);
                    for ($i = 0; $i < $n; $i++) { $store[$i * 7] = true; }
                    break;
                default:
                    fwrite(STDERR, "unknown workload $workload\n");
                    exit(2);
            }
            $count      = count($store);
            $indexBytes = $store->memoryUsage();
        }

        $heapBytes = memory_get_usage() - $before;

        // Touch the structure so a clever optimiser cannot elide the build, and so
        // the pages are genuinely resident when peak RSS is read.
        if ($workload !== 'floor' && $count > 0) {
            $checksum = $count;
        }

        echo json_encode([
            'workload'     => $workload,
            'impl'         => $impl,
            'n'            => $n,
            'count'        => $count,
            'checksum'     => $checksum,
            'peak_rss'     => tam_peak_rss(),
            'index_bytes'  => $indexBytes,   // Judy::memoryUsage(); null for arrays
            'heap_bytes'   => $heapBytes,    // emalloc only — understates Judy
            'php_peak'     => memory_get_peak_usage(true),
        ]), "\n";
        exit(0);
}

// ── Arm A row classification ────────────────────────────────────────────────
//
// judy-bench.php names its comparison rows `<id>.judy` and `<id>.php`. The
// `.php` suffix does NOT uniformly mean "PHP native array": in several groups
// the `.php` closure still operates on a Judy instance, measuring "PHP userland
// loop over Judy" against "Judy native method". Those rows are a legitimate API
// comparison but they are NOT arm A, and quoting them as "PHP array vs Judy"
// would be a fabricated claim.
//
// TAM_ARM_A_ROWS lists the ids whose `.php` arm is a genuine PHP native array,
// verified by reading each closure's `use (...)` clause and body. Everything
// else is recorded under `judy_vs_phploop` instead, and labelled as such.
//
// Populated by auditing every `.php` closure in judy-bench.php: its `use (...)`
// clause and the first operation in its body. The audit found three traps:
//
//   - adv.iter's `.php` rows (`adv.forEach|filter|map.*`) capture `$j_int` /
//     `$j_str`, which are Judy INSTANCES. They measure a PHP foreach over Judy
//     against Judy's native method — no PHP array is involved anywhere.
//   - api.setop's `int_to_int` and `string_to_int` `.php` arms build their
//     result into `new Judy(...)` and iterate Judy sources. ONLY the `bitset`
//     arms use real arrays (array_replace / array_intersect_key /
//     array_diff_key over `$php_a` / `$php_b`).
//   - api.batch is almost entirely Judy-vs-Judy; `api.increment.int_to_int` is
//     the single genuine array row in that group.
//
// `.heap` rows are excluded here on purpose: judy-bench.php records them with
// `median_ms = 0` and the payload in `heap_bytes`, so they carry no timing.
const TAM_ARM_A_ROWS = [
    // core.* — bench_core_type() times caller-supplied closures, and every
    // call site's `$populate_php` / `$read_php` builds and reads a real array.
    'core.bitset.write', 'core.bitset.read', 'core.bitset.iter', 'core.bitset.free',
    'core.int_to_int.write', 'core.int_to_int.read', 'core.int_to_int.iter', 'core.int_to_int.free',
    'core.int_to_mixed.write', 'core.int_to_mixed.read', 'core.int_to_mixed.iter', 'core.int_to_mixed.free',
    'core.int_to_packed.write', 'core.int_to_packed.read', 'core.int_to_packed.iter', 'core.int_to_packed.free',
    'core.string_to_int.write', 'core.string_to_int.read', 'core.string_to_int.iter', 'core.string_to_int.free',
    'core.string_to_mixed.write', 'core.string_to_mixed.read', 'core.string_to_mixed.iter', 'core.string_to_mixed.free',
    'core.string_to_int_hash.write', 'core.string_to_int_hash.read', 'core.string_to_int_hash.iter', 'core.string_to_int_hash.free',
    'core.string_to_mixed_hash.write', 'core.string_to_mixed_hash.read', 'core.string_to_mixed_hash.iter', 'core.string_to_mixed_hash.free',
    'core.string_to_int_adaptive.write', 'core.string_to_int_adaptive.read', 'core.string_to_int_adaptive.iter', 'core.string_to_int_adaptive.free',
    'core.string_to_mixed_adaptive.write', 'core.string_to_mixed_adaptive.read', 'core.string_to_mixed_adaptive.iter', 'core.string_to_mixed_adaptive.free',
    // api.batch — the only true-array row in the group.
    'api.increment.int_to_int',
    // api.setops — BITSET arms only; the int_to_int / string_to_int arms build
    // into a Judy and are recorded as judy_vs_phploop instead.
    'api.setop.union.bitset', 'api.setop.intersect.bitset',
    'api.setop.diff.bitset', 'api.setop.xor.bitset',
];

// ── Small statistics library ────────────────────────────────────────────────
//
// Same estimators as scripts/bench-compare.php and
// examples/benchmarks/judy-bench-alternatives.php: median point estimate with a
// 95% percentile-bootstrap CI, seeded so a rerun of the same samples reproduces
// the same interval.

function tam_median(array $xs): float
{
    sort($xs);
    $c = count($xs);
    if ($c === 0) { return 0.0; }
    return $c % 2 ? (float) $xs[intdiv($c, 2)] : ((float) $xs[$c / 2 - 1] + (float) $xs[$c / 2]) / 2;
}

/** 95% percentile-bootstrap CI for the median. */
function tam_median_ci(array $xs, int $resamples = 2000): array
{
    $n = count($xs);
    if ($n === 0) { return [0.0, 0.0]; }
    if ($n < 3)   { return [(float) min($xs), (float) max($xs)]; }
    mt_srand(20260818);
    $meds = [];
    for ($b = 0; $b < $resamples; $b++) {
        $s = [];
        for ($i = 0; $i < $n; $i++) { $s[] = $xs[mt_rand(0, $n - 1)]; }
        $meds[] = tam_median($s);
    }
    sort($meds);
    return [$meds[(int) floor(0.025 * $resamples)], $meds[(int) ceil(0.975 * $resamples) - 1]];
}

function tam_spread_pct(array $xs): float
{
    $m = tam_median($xs);
    if ($m <= 0.0) { return 0.0; }
    return (max($xs) - min($xs)) / $m * 100.0;
}

/**
 * Verdict for a ratio series against a per-residency claim floor.
 *
 * A cell is only allowed to claim a direction when the WHOLE confidence
 * interval clears the floor. A point estimate past the floor with a CI that
 * straddles it is "null" — inside demonstrated noise — not a win.
 */
function tam_verdict(array $ratios, float $floor_pct): array
{
    if (count($ratios) < 3) {
        return ['status' => 'null', 'reason' => 'too few paired rounds'];
    }
    $ratio = tam_median($ratios);
    $ci    = tam_median_ci($ratios);
    $lo    = 1.0 - $floor_pct / 100.0;
    $hi    = 1.0 + $floor_pct / 100.0;

    if ($ci[1] < $lo) {
        $status = 'FASTER';
        $reason = null;
    } elseif ($ci[0] > $hi) {
        $status = 'SLOWER';
        $reason = null;
    } else {
        $status = 'null';
        $reason = sprintf('inside the %.1f%% claim floor (CI straddles it)', $floor_pct);
    }
    return [
        'status'       => $status,
        'reason'       => $reason,
        'ratio'        => round($ratio, 5),
        'delta_pct'    => round(($ratio - 1.0) * 100.0, 2),
        'ci_delta_pct' => [round(($ci[0] - 1.0) * 100.0, 2), round(($ci[1] - 1.0) * 100.0, 2)],
        'n'            => count($ratios),
    ];
}

// ── Host hygiene ────────────────────────────────────────────────────────────
//
// The rule the project works to: a benchmark is only trustworthy when the box
// is quiet, and "quiet" means load average below N/2 for an N-core host. A
// sibling process eating cores can manufacture a 3x "regression" with no code
// change whatsoever, so load is sampled at every phase boundary rather than
// once at the start.

function tam_cpu_count(): int
{
    static $n = null;
    if ($n !== null) { return $n; }

    if (PHP_OS_FAMILY === 'Darwin') {
        $n = (int) trim((string) @shell_exec('sysctl -n hw.ncpu 2>/dev/null'));
        return $n = max(1, $n);
    }

    // Windows has neither /proc nor nproc. The environment carries the width,
    // and reading it beats falling through to the "unknown" return, which would
    // deprive the run of a threshold entirely.
    if (PHP_OS_FAMILY === 'Windows') {
        $n = (int) getenv('NUMBER_OF_PROCESSORS');
        return $n = max(0, $n);
    }

    // Order matters. `nproc` is absent from slim container images, and falling
    // back to 1 there is not a harmless default: it drops the hygiene threshold
    // to 0.5 and marks a perfectly idle 24-core host contaminated, suppressing
    // every verdict in the run. Count real siblings from /proc/cpuinfo first
    // and treat the external command as the fallback, not the primary.
    if (is_readable('/proc/cpuinfo')) {
        $c = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
        if ($c > 0) { return $n = $c; }
    }
    // A cpuset (docker --cpuset-cpus, taskset) is the effective width when it
    // is narrower than the machine.
    if (function_exists('posix_getpid') && is_readable('/proc/self/status')) {
        if (preg_match('/^Cpus_allowed_list:\s*(\S+)/m', (string) @file_get_contents('/proc/self/status'), $m)) {
            $w = 0;
            foreach (explode(',', $m[1]) as $part) {
                if (str_contains($part, '-')) {
                    [$a, $b] = array_map('intval', explode('-', $part, 2));
                    $w += max(0, $b - $a + 1);
                } elseif ($part !== '') {
                    $w++;
                }
            }
            if ($w > 0) { return $n = $w; }
        }
    }
    $c = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
    if ($c > 0) { return $n = $c; }

    // Unknown rather than 1: refuse to invent a threshold that would silently
    // condemn or bless the run.
    return $n = 0;
}

function tam_load_snapshot(string $phase): array
{
    $load1 = null;
    if (function_exists('sys_getloadavg')) {
        $la    = sys_getloadavg();
        $load1 = $la === false ? null : round((float) $la[0], 2);
    }

    // Snapshots are taken at PHASE BOUNDARIES, when none of this driver's own
    // benchmark children are running. Anything heavy at that moment is somebody
    // else's work, so the foreign-CPU total below is a direct co-tenancy
    // detector rather than a guess.
    //
    // This exists because load average alone is NOT sufficient. Two benchmark
    // campaigns once ran concurrently on a 24-core host, each individually
    // passing the load < N/2 gate at load ~2, and corrupted each other anyway:
    // a co-resident memory-bound benchmark contends for last-level cache and
    // memory bandwidth no matter where the scheduler puts it. The damage was
    // 2.2x on an untouched baseline arm. Worse, a PHP-array control does not
    // notice — array operations are not pointer-chasing and not DRAM-bound, so
    // the control stayed flat while every Judy cell moved.
    $top  = [];
    $self = getmypid();
    if (PHP_OS_FAMILY === 'Windows') {
        // No `ps`, and `tasklist` reports memory but not instantaneous CPU, so
        // the foreign-tenant detector simply does not run here. It is reported
        // as unavailable rather than as "nothing found", because those are very
        // different statements and only one of them is true.
        return [
            'phase' => $phase, 'at' => date('Y-m-d\TH:i:sP'),
            'load1' => null, 'cpus' => tam_cpu_count() ?: null,
            'threshold' => null, 'over' => false,
            'cpus_known' => tam_cpu_count() > 0,
            'foreign_cpu_pct' => null, 'foreign_busy' => false,
            'heavy' => [],
            'unavailable' => 'no load average and no per-process CPU sampling on Windows',
        ];
    }
    if (PHP_OS_FAMILY === 'Darwin') {
        $raw = (string) shell_exec("ps -A -o pid,pcpu,rss,comm -r 2>/dev/null | head -12");
    } else {
        $raw = (string) shell_exec("ps -A -o pid,pcpu,rss,comm --sort=-pcpu 2>/dev/null | head -12");
    }
    foreach (array_slice(array_filter(explode("\n", trim($raw))), 1) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        $parts = preg_split('/\s+/', $line, 4);
        if (count($parts) !== 4) { continue; }
        [$pid, $cpu, $rss, $cmd] = $parts;
        if ((int) $pid === $self) { continue; }
        if ((float) $cpu > 5.0) {
            $top[] = ['pid' => (int) $pid, 'cpu_pct' => (float) $cpu, 'rss_kb' => (int) $rss, 'cmd' => $cmd];
        }
    }
    $foreign = 0.0;
    foreach ($top as $t) { $foreign += $t['cpu_pct']; }

    $cpus = tam_cpu_count();
    return [
        'phase'     => $phase,
        'at'        => date('Y-m-d\TH:i:sP'),
        'load1'     => $load1,
        'cpus'      => $cpus ?: null,
        'threshold' => $cpus ? $cpus / 2 : null,
        // Unknown CPU width is reported, never guessed: an invented threshold
        // would either condemn a clean run or bless a dirty one.
        'over'      => $cpus > 0 && $load1 !== null && $load1 > $cpus / 2,
        'cpus_known'=> $cpus > 0,
        // Half a core of foreign work is enough to move a DRAM-bound cell.
        'foreign_cpu_pct' => round($foreign, 1),
        'foreign_busy'    => $foreign > 50.0,
        'heavy'     => $top,
    ];
}

// ── Per-arm PHP invocation ──────────────────────────────────────────────────
//
// The extension is selected with PHP_INI_SCAN_DIR pointing at a directory
// holding exactly one judy.ini, NOT with `-d extension=`. On an image that
// already enables judy in conf.d — which the project's own bench image does —
// `-d extension=` is a silent no-op ("Module already loaded") and the run
// measures the pre-installed copy while reporting the path you passed. That
// failure mode has bitten this project before: the stale in-image judy.so wins
// and judy_version() does not reveal it, because both copies report the same
// version string. Only the mapped-path check below can tell them apart.

/** Register an ini scan dir for one build and return its handle. */
function tam_register(string $handle, string $so): void
{
    global $tmp_root, $arm_ini;
    $dir = "$tmp_root/$handle-ini";
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        fwrite(STDERR, "cannot create $dir\n");
        exit(2);
    }
    file_put_contents("$dir/judy.ini", 'extension=' . realpath($so) . "\n");
    $arm_ini[$handle] = $dir;
}

register_shutdown_function(static function () use (&$tmp_root) {
    foreach (glob("$tmp_root/*-ini/judy.ini") ?: [] as $f) { @unlink($f); }
    foreach (glob("$tmp_root/*-ini") ?: [] as $d) { @rmdir($d); }
    foreach (glob("$tmp_root/*") ?: [] as $f) { @is_file($f) && @unlink($f); }
    @rmdir($tmp_root);
});

/**
 * Command prefix for one arm. `$handle === 'array'` means arm A: no judy at
 * all, so PHP_INI_SCAN_DIR points at an empty directory to guarantee the
 * extension cannot be loaded even if the host php.ini would have enabled it.
 */
function tam_php(string $handle): string
{
    global $arm_ini, $tmp_root;

    if ($handle === 'array') {
        // Arm A must differ from the judy arms ONLY in that judy is absent, so
        // it gets an EMPTY scan dir rather than `-n`: the empty directory
        // suppresses every conf.d extension exactly as the judy arms'
        // single-file scan dir does, leaving the rest of the configuration
        // identical.
        $dir = "$tmp_root/none-ini";
        if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    } else {
        $dir = $arm_ini[$handle];
    }

    // Windows has no `VAR=value command` prefix syntax — cmd.exe would try to
    // execute a program literally named `PHP_INI_SCAN_DIR=...` — so the
    // variable is exported into THIS process instead and inherited by the
    // child. putenv() is process-wide and the arms alternate, so it must be
    // re-applied immediately before every exec; that is exactly what happens,
    // because every caller builds its command line through this function and
    // runs it straight away.
    if (PHP_OS_FAMILY === 'Windows') {
        putenv('PHP_INI_SCAN_DIR=' . $dir);
        return escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 ';
    }

    // On POSIX the inline prefix is kept: it scopes the variable to the one
    // command, so nothing this driver does can leak into an unrelated child.
    return 'PHP_INI_SCAN_DIR=' . escapeshellarg($dir) . ' '
        . escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 ';
}

/**
 * Prove that the arm really loaded the .so we asked for.
 *
 * Checks, in order: the extension loaded at all; no "already loaded" warning;
 * the main php.ini does not itself pull in a judy; exactly one scanned ini; and
 * — on Linux, where /proc/self/maps is available — that the set of mapped judy
 * object paths is exactly the realpath we intended. Without this last check a
 * pre-enabled copy is indistinguishable from the one under test.
 */
function tam_verify(string $handle, string $so): array
{
    // On Windows there is no /proc/self/maps, so `$paths` comes back empty and
    // the mapped-path assertion below is skipped. The remaining checks (the
    // extension loaded, no "already loaded" warning, the main php.ini does not
    // itself pull in a judy, exactly one scanned ini) still hold, and on a
    // freshly provisioned CI runner no pre-installed judy exists to win.
    $probe = <<<'PHP'
$paths = [];
if (@is_readable('/proc/self/maps')) {
    preg_match_all('#/\S*judy\S*\.so#', (string)file_get_contents('/proc/self/maps'), $m);
    $paths = array_values(array_unique($m[0]));
}
$main = php_ini_loaded_file();
echo json_encode([
    'loaded'  => (int)extension_loaded('judy'),
    'version' => extension_loaded('judy') ? judy_version() : null,
    'paths'   => $paths,
    'inis'    => php_ini_scanned_files() ? array_values(array_filter(array_map('trim', explode(',', php_ini_scanned_files())), 'strlen')) : [],
    'main_loads_judy' => $main && preg_match('#^\s*(zend_)?extension\s*=.*judy#mi', (string)@file_get_contents($main)) ? 1 : 0,
]);
PHP;
    $err = sys_get_temp_dir() . '/judy-threearm-verify-' . getmypid() . '.err';
    $out = shell_exec(tam_php($handle) . '-r ' . escapeshellarg($probe) . ' 2> ' . escapeshellarg($err));
    $stderr = (string) @file_get_contents($err);
    @unlink($err);

    if (stripos($stderr, 'already loaded') !== false || stripos($stderr, 'Unable to load dynamic library') !== false) {
        fwrite(STDERR, "arm $handle: extension loading is not under our control:\n$stderr\n");
        exit(1);
    }
    $j = json_decode((string) $out, true);
    if (!is_array($j) || !isset($j['loaded'])) {
        fwrite(STDERR, "arm $handle: unusable probe output: $out\n$stderr\n");
        exit(1);
    }
    if (!$j['loaded']) {
        fwrite(STDERR, "arm $handle: judy did not load from $so\n$stderr\n");
        exit(1);
    }
    if (!empty($j['main_loads_judy'])) {
        fwrite(STDERR, "arm $handle: the main php.ini itself loads a judy extension; cannot isolate arms\n");
        exit(1);
    }
    if (count($j['inis']) > 1) {
        fwrite(STDERR, "arm $handle: more than one scanned ini: " . implode(', ', $j['inis']) . "\n");
        exit(1);
    }
    $want = realpath($so);
    if ($j['paths'] && $j['paths'] !== [$want]) {
        fwrite(STDERR, "arm $handle: mapped judy objects " . implode(', ', $j['paths'])
            . " do not match the requested $want — a pre-installed copy is winning\n");
        exit(1);
    }
    return $j;
}

/** Confirm arm A really has no judy loaded. */
function tam_verify_array_arm(): void
{
    $out = shell_exec(tam_php('array') . '-r ' . escapeshellarg('echo (int)extension_loaded("judy");') . ' 2> ' . TAM_DEVNULL);
    if (trim((string) $out) !== '0') {
        fwrite(STDERR, "arm A: judy is loaded in the PHP-array arm; it must not be\n");
        exit(1);
    }
}

// ── Memory phase ────────────────────────────────────────────────────────────

/**
 * Measure one memory cell: peak RSS over $runs clean child processes, with the
 * matching per-arm empty-process floor subtracted.
 *
 * The floor matters: arm A's process never loads the extension while B and C
 * do, so charging the raw peak to the data structure would credit Judy with the
 * extension's own footprint (or, worse, flatter the array arm by the same
 * amount). Subtracting a per-arm floor removes the interpreter and the loaded
 * .so from every cell.
 */
function tam_mem_cell(string $handle, string $workload, string $impl, int $n, int $runs, string $driver): ?array
{
    // The DRIVER re-execs itself, not this library: tam_mem_child_main() runs
    // from the driver's entry point, so the child must be the driver.
    $self = $driver;
    $rss = $idx = $heap = [];
    for ($r = 0; $r < $runs; $r++) {
        $cmd = tam_php($handle) . escapeshellarg($self)
            . ' --mem-child ' . escapeshellarg($workload) . ' ' . escapeshellarg($impl) . ' ' . $n
            . ' 2> ' . TAM_DEVNULL;
        $j = json_decode(trim((string) shell_exec($cmd)), true);
        if (!is_array($j) || !isset($j['peak_rss'])) { return null; }
        $rss[] = (float) $j['peak_rss'];
        if ($j['index_bytes'] !== null) { $idx[] = (float) $j['index_bytes']; }
        $heap[] = (float) $j['heap_bytes'];
        $count  = (int) $j['count'];
    }
    return [
        'peak_rss_bytes'  => (int) tam_median($rss),
        'peak_rss_ci'     => array_map('intval', tam_median_ci($rss)),
        'index_bytes'     => $idx ? (int) tam_median($idx) : null,
        'php_heap_bytes'  => (int) tam_median($heap),
        'count'           => $count ?? 0,
        'runs'            => $runs,
    ];
}

function tam_fmt_bytes(int $b): string
{
    if ($b < 0) { return '-' . tam_fmt_bytes(-$b); }
    if ($b >= 1073741824) { return sprintf('%.2f GB', $b / 1073741824); }
    if ($b >= 1048576)    { return sprintf('%.1f MB', $b / 1048576); }
    if ($b >= 1024)       { return sprintf('%.1f KB', $b / 1024); }
    return $b . ' B';
}
