<?php
/**
 * Three-arm performance and memory benchmark driver.
 *
 * The three arms
 * --------------
 *   A  PHP native array          — the "why use php-judy at all" baseline.
 *   B  php-judy + SYSTEM libJudy — what a user gets today from PECL plus a
 *                                  distro/Homebrew libJudy (`--with-judy=DIR`).
 *   C  php-judy + BUNDLED tree   — what they get from the vendored libJudy
 *                                  (default build): P1-P7 correctness patches,
 *                                  O1 popcount, O3 word-access metadata, O4
 *                                  string-layer, pinned isolated vendor CFLAGS.
 *
 * B vs C is the headline: it isolates this project's contribution, because the
 * ONLY difference between the two arms is which libJudy is linked. A vs C
 * answers "should I use php-judy", and its honest answer is not uniformly
 * favourable — see the "where PHP arrays win" rows in the output.
 *
 * Methodology this driver enforces
 * --------------------------------
 *  1. SAME TOOLCHAIN, SAME SOURCE. This driver does not build anything; it
 *     takes two `.so` paths. The caller MUST produce them from one working
 *     tree with one compiler and one PHP, changing only `--with-judy`. A
 *     distro- or PECL-installed `judy.so` is NOT a valid arm B: a prior
 *     comparison did exactly that and its ~9.4% "win" turned out to bundle
 *     toolchain provenance differences (BENCHMARK.md / FINDINGS §11.10).
 *     `--assert-same-source` records the caller's attestation into the JSON so
 *     a reader can see whether the rule was honoured.
 *
 *  2. LIBJUDY PROVENANCE IS RECORDED, NOT ASSUMED. `--system-provenance`
 *     carries the distro, package version and patch status of arm B's library.
 *     This matters: Debian/Ubuntu and Fedora ship Judy 1.0.5 *with* the Baskins
 *     `jp_1Index` fix (Debian patch 04, the same undefined behaviour the
 *     bundled tree fixes as P1), while Homebrew ships pristine 1.0.5 with no
 *     patches at all. "System libJudy" therefore means different code on
 *     different platforms, and a B-vs-C delta is only interpretable next to it.
 *
 *  3. ARMS ARE INTERLEAVED, NEVER RUN BACK-TO-BACK. Timing runs one benchmark
 *     *group* at a time and alternates the arm order every round (ABBA), so the
 *     two arms' measurements of a given group sit seconds apart rather than
 *     minutes. Sequential suites produced false regressions before (#87). All
 *     statistics are computed on per-round PAIRED RATIOS, so whatever the
 *     machine was doing during round r hits both members of that round's pair
 *     and divides out.
 *
 *     A vs C is paired more tightly still: judy-bench.php measures its PHP-array
 *     rows and its Judy rows inside the SAME process, microseconds apart, so the
 *     A/C ratio is immune to between-process drift entirely.
 *
 *  4. CLAIM FLOORS ARE PER-RESIDENCY, AND MEASURED IN THIS RUN. Pooled controls
 *     across four measurement rounds put the floor at ~3% for cache-resident
 *     cells and ~1.3% out-of-cache (FINDINGS §11.10). This driver does not
 *     merely assume those: it runs two controls and reports what they measured
 *     here. Anything inside the floor is reported as null, never as a claim.
 *
 *       - PHP-only control: the `.php` rows do not execute one instruction of
 *         libJudy, so a B-vs-C difference on them is pure runner movement.
 *       - C-vs-C rebuild control: pass the same arm twice (or two independently
 *         linked C builds) and every cell should read null. Cells that do not
 *         are measuring binary layout, not libJudy.
 *
 *  5. HOST HYGIENE IS GATED, NOT HOPED FOR. Load is sampled before, between and
 *     after the phases. The threshold is N/2 for an N-core box. Over it, the
 *     run is marked `contaminated` and every verdict is suppressed to null:
 *     the numbers stay in the JSON as diagnostics but assert nothing.
 *
 *  6. MULTIPLE BUILDS PER ARM. Pass `--system-so`/`--bundled-so` more than once
 *     to rotate independently linked builds across rounds. The per-build spread
 *     is reported so a layout artifact in one binary cannot masquerade as a
 *     libJudy effect. Fewer builds is permitted for PHP-level end-to-end work
 *     but then the confidence tier must be labelled honestly.
 *
 * Memory is a first-class axis, not a footnote
 * --------------------------------------------
 * php-judy's measured, defensible headline is MEMORY, not uniform speed. The
 * existing judy-bench.php `heap_bytes` rows use `memory_get_usage()`, which
 * only sees PHP's emalloc heap — libJudy allocates through malloc, OUTSIDE it,
 * so those rows badly understate Judy's real footprint and are not usable for a
 * memory claim. This driver therefore measures PEAK RSS in a dedicated child
 * process per (arm, workload, size), the same approach
 * examples/coverage-index.php and judy-bench-alternatives.php use, and
 * subtracts a per-arm empty-process floor so the interpreter and the loaded
 * extension are not charged to the data structure.
 *
 * Usage
 * -----
 *   php scripts/bench-threearm.php \
 *       --system-so  /path/to/build-B/judy.so \
 *       --bundled-so /path/to/build-C/judy.so \
 *       [--rounds 7] [--size 300000] [--iterations 3] \
 *       [--groups core.int,core.str,api.batch,api.setops,adv.iter] \
 *       [--mem-sizes 100000,1000000,8000000] \
 *       [--skip-memory] [--skip-timing] \
 *       [--system-provenance "Debian 13 / libjudy-dev 1.0.5-5.1 / patch 04 applied"] \
 *       [--assert-same-source] [--label "linux-x86_64-gcc14"] \
 *       [--out bench-threearm.json]
 *
 * A C-vs-C rebuild control is just the same driver with two C builds:
 *   php scripts/bench-threearm.php --system-so C1.so --bundled-so C2.so ...
 *
 * Output: a JSON document (schema `judy-bench-threearm/1`) plus a human table.
 */

// ── Shared machinery ────────────────────────────────────────────────────────
//
// Statistics, host hygiene, arm registration/verification, the arm-A row
// classification and the peak-RSS memory child all live in scripts/bench-lib.php
// so that scripts/bench-gate.php — the recurring CI regression gate — inherits
// exactly this apparatus rather than an approximation of it. Required at
// top-level scope on purpose: the ini-cleanup shutdown handler closes over
// $tmp_root there.

require_once __DIR__ . '/bench-lib.php';

// Must run before this driver's own CLI parsing: tam_mem_cell() re-execs this
// file with --mem-child to measure peak RSS in a clean process.
tam_mem_child_main($argv);


// ── CLI ─────────────────────────────────────────────────────────────────────

$opts = getopt('', [
    'system-so:', 'bundled-so:', 'bench:', 'rounds:', 'size:', 'iterations:',
    'groups:', 'mem-sizes:', 'mem-workloads:', 'mem-runs:',
    'cache-floor:', 'dram-floor:', 'dram-size:', 'min-ms:',
    'system-provenance:', 'bundled-provenance:', 'label:',
    'assert-same-source', 'skip-memory', 'skip-timing', 'allow-contaminated',
    'out:', 'quiet',
]);

/** getopt returns a string for one occurrence and an array for several. */
function tam_multi(array $opts, string $key): array
{
    if (!isset($opts[$key])) { return []; }
    return is_array($opts[$key]) ? $opts[$key] : [$opts[$key]];
}

$system_sos  = tam_multi($opts, 'system-so');
$bundled_sos = tam_multi($opts, 'bundled-so');

if (!$system_sos || !$bundled_sos) {
    fwrite(STDERR, <<<USAGE
Usage: bench-threearm.php --system-so <path> --bundled-so <path> [options]

  --system-so PATH        arm B: php-judy linked against a SYSTEM libJudy.
                          Repeat for several independently linked builds.
  --bundled-so PATH       arm C: php-judy linked against the BUNDLED libJudy.
                          Repeat for several builds. Passing two C builds turns
                          the run into the C-vs-C rebuild control.
  --rounds N              interleaved ABBA rounds per group (default 7).
  --size N                elements for the timing suite (default 300000).
  --iterations N          timed repeats inside each child (default 3).
  --groups A,B,...        judy-bench.php groups (default core.int,core.str,
                          api.batch,api.setops,adv.iter).
  --mem-sizes A,B,...     element counts for the memory matrix
                          (default 100000,1000000,8000000).
  --mem-workloads A,B,... default int_to_int,int_sparse,int_to_mixed,
                          string_to_int,bitset.
  --mem-runs N            repeats per memory cell (default 3).
  --min-ms MS             ignore rows shorter than this in both arms; a row too
                          short to time carries no signal (default 0.5).
  --cache-floor PCT       claim floor for cache-resident cells (default 3.0).
  --dram-floor PCT        claim floor out-of-cache (default 1.3).
  --dram-size N           element count at/above which a cell counts as
                          out-of-cache (default 4000000).
  --system-provenance S   distro / package / patch status of arm B's libJudy.
  --bundled-provenance S  description of arm C's vendored tree.
  --label S               short platform label recorded in the JSON.
  --assert-same-source    caller attests both .so came from one source tree
                          and one toolchain, differing only in --with-judy.
  --skip-memory           timing phase only.
  --skip-timing           memory phase only.
  --allow-contaminated    record numbers even if the host hygiene gate fails
                          (they are still marked contaminated and assert nothing).
  --out FILE              JSON output (default bench-threearm.json).

USAGE);
    exit(2);
}

foreach (array_merge($system_sos, $bundled_sos) as $so) {
    if (!is_file($so)) {
        fwrite(STDERR, "No such extension: $so\n");
        exit(2);
    }
}

$bench_script = $opts['bench'] ?? __DIR__ . '/../examples/benchmarks/judy-bench.php';
if (!is_file($bench_script)) {
    fwrite(STDERR, "No such benchmark script: $bench_script\n");
    exit(2);
}

$rounds      = max(3, (int) ($opts['rounds']     ?? 7));
$size        = max(1, (int) ($opts['size']       ?? 300000));
$iterations  = max(1, (int) ($opts['iterations'] ?? 3));
$mem_runs    = max(1, (int) ($opts['mem-runs']   ?? 3));
$min_ms      = (float) ($opts['min-ms'] ?? 0.5);
$cache_floor = (float) ($opts['cache-floor'] ?? 3.0);
$dram_floor  = (float) ($opts['dram-floor']  ?? 1.3);
$dram_size   = (int) ($opts['dram-size']     ?? 4000000);
$out_file    = $opts['out'] ?? 'bench-threearm.json';
$quiet       = isset($opts['quiet']);
$skip_memory = isset($opts['skip-memory']);
$skip_timing = isset($opts['skip-timing']);
$allow_dirty = isset($opts['allow-contaminated']);
$label       = $opts['label'] ?? (PHP_OS . '-' . php_uname('m'));

$groups = array_values(array_filter(array_map('trim', explode(
    ',', (string) ($opts['groups'] ?? 'core.int,core.str,api.batch,api.setops,adv.iter')
)), 'strlen'));

$mem_sizes = array_values(array_filter(array_map('intval', explode(
    ',', (string) ($opts['mem-sizes'] ?? '100000,1000000,8000000')
))));

$mem_workloads = array_values(array_filter(array_map('trim', explode(
    ',', (string) ($opts['mem-workloads'] ?? 'int_to_int,int_sparse,int_to_mixed,string_to_int,bitset')
)), 'strlen'));

// ── Timing phase ────────────────────────────────────────────────────────────

/** Run one judy-bench.php group under one arm and return its benchmarks map. */
function tam_run_group(string $handle, string $group, int $size, int $iterations, string $bench_script): array
{
    global $tmp_root, $arm_meta;
    $json = "$tmp_root/out-$handle.json";
    $err  = "$tmp_root/err-$handle.txt";
    @unlink($json);

    $cmd = tam_php($handle)
        . escapeshellarg($bench_script)
        . ' --group ' . escapeshellarg($group)
        . ' --size ' . $size
        . ' --iterations ' . $iterations
        . ' --json ' . escapeshellarg($json)
        . ' > ' . TAM_DEVNULL . ' 2> ' . escapeshellarg($err);

    exec($cmd, $_, $status);
    $stderr = (string) @file_get_contents($err);
    if (stripos($stderr, 'already loaded') !== false || stripos($stderr, 'Unable to load dynamic library') !== false) {
        fwrite(STDERR, "arm $handle: extension loading broke mid-run:\n$stderr\n");
        exit(1);
    }
    if ($status !== 0 || !is_file($json)) {
        fwrite(STDERR, "arm $handle: group $group failed (status $status)\n$stderr\n");
        exit(1);
    }
    $data = json_decode((string) file_get_contents($json), true);
    if (!is_array($data) || !isset($data['benchmarks'])) {
        fwrite(STDERR, "arm $handle: group $group produced no benchmarks\n");
        exit(1);
    }
    $arm_meta[$handle] = $data['metadata'] ?? [];
    return $data['benchmarks'];
}

// ── Setup ───────────────────────────────────────────────────────────────────

$arm_meta = [];

// Register every build under its own handle so several independently linked
// binaries of the same arm can be rotated across rounds.
$b_handles = [];
foreach ($system_sos as $i => $so)  { $h = 'B' . ($i + 1); tam_register($h, $so); $b_handles[] = $h; }
$c_handles = [];
foreach ($bundled_sos as $i => $so) { $h = 'C' . ($i + 1); tam_register($h, $so); $c_handles[] = $h; }

// A run whose two arms are byte-identical, or whose "system" arm is in fact a
// second bundled build, is a CONTROL run: every cell should read null, and any
// cell that does not is measuring binary layout rather than libJudy.
$b_hashes = array_map(fn($p) => hash_file('sha256', $p), $system_sos);
$c_hashes = array_map(fn($p) => hash_file('sha256', $p), $bundled_sos);
$identical = count(array_unique(array_merge($b_hashes, $c_hashes))) === 1;

if (!$quiet) {
    fwrite(STDERR, "php-judy three-arm benchmark\n");
    fwrite(STDERR, "  A  PHP native array\n");
    fwrite(STDERR, "  B  php-judy + system libJudy  : " . implode(', ', $system_sos) . "\n");
    fwrite(STDERR, "  C  php-judy + bundled libJudy : " . implode(', ', $bundled_sos) . "\n");
    if ($identical) {
        fwrite(STDERR, "  NOTE: all builds are byte-identical — this is a self-comparison control run.\n");
    }
    if (!isset($opts['assert-same-source'])) {
        fwrite(STDERR, "  WARNING: --assert-same-source not given. A B-vs-C delta is only\n");
        fwrite(STDERR, "           interpretable when both .so came from ONE source tree and ONE\n");
        fwrite(STDERR, "           toolchain, differing only in --with-judy.\n");
    }
}

// Prove each handle really maps the .so we intend, and that arm A has no judy.
$verify = [];
foreach ($b_handles as $i => $h) { $verify[$h] = tam_verify($h, $system_sos[$i]); }
foreach ($c_handles as $i => $h) { $verify[$h] = tam_verify($h, $bundled_sos[$i]); }
tam_verify_array_arm();

$loads   = [];
$loads[] = tam_load_snapshot('start');
$started = microtime(true);

// ── Phase 1: interleaved timing ─────────────────────────────────────────────

/** @var array<string,array<string,array<float>>> class => id => ms[] */
$judy_ms = ['B' => [], 'C' => []];
/** @var array<string,array<string,array<float>>> class => id => ms[] */
$php_ms  = ['B' => [], 'C' => []];
/** @var array<string,array<string,array<int>>> class => id => bytes[] */
$heaps   = ['B' => [], 'C' => []];
/** per-round record of which concrete build served each class */
$build_of = ['B' => [], 'C' => []];

$timing_children = 0;

if (!$skip_timing) {
    foreach ($groups as $group) {
        for ($r = 1; $r <= $rounds; $r++) {
            // Rotate builds so no single binary's page layout can carry a cell.
            $bh = $b_handles[($r - 1) % count($b_handles)];
            $ch = $c_handles[($r - 1) % count($c_handles)];
            $build_of['B'][] = $bh;
            $build_of['C'][] = $ch;

            // ABBA: alternate which arm goes first so linear drift cancels
            // instead of loading onto whichever arm always ran second.
            $order = ($r % 2 === 1) ? [['B', $bh], ['C', $ch]] : [['C', $ch], ['B', $bh]];

            foreach ($order as [$class, $handle]) {
                $bm = tam_run_group($handle, $group, $size, $iterations, $bench_script);
                $timing_children++;
                foreach ($bm as $id => $entry) {
                    if (array_key_exists('heap_bytes', $entry)) {
                        $heaps[$class][$id][] = (int) $entry['heap_bytes'];
                        continue;
                    }
                    if (str_ends_with($id, '.judy')) {
                        $judy_ms[$class][substr($id, 0, -5)][] = (float) $entry['median_ms'];
                    } elseif (str_ends_with($id, '.php')) {
                        $php_ms[$class][substr($id, 0, -4)][] = (float) $entry['median_ms'];
                    }
                }
            }
            if (!$quiet) {
                fwrite(STDERR, sprintf("  timing %-12s round %d/%d\r", $group, $r, $rounds));
            }
        }
        if (!$quiet) { fwrite(STDERR, sprintf("  timing %-12s done          \n", $group)); }
    }
    $loads[] = tam_load_snapshot('after-timing');
}

// ── Phase 2: peak-RSS memory matrix ─────────────────────────────────────────

$memory = [];
$mem_children = 0;

if (!$skip_memory) {
    // Per-arm empty-process floor. Arm A loads no extension; B and C each load
    // theirs. Subtracting the matching floor keeps the interpreter and the .so
    // itself out of every data-structure number.
    $floor = [];
    foreach ([['A', 'array'], ['B', $b_handles[0]], ['C', $c_handles[0]]] as [$class, $handle]) {
        $cell = tam_mem_cell($handle, 'floor', $class === 'A' ? 'array' : 'judy', 0, $mem_runs, __FILE__);
        $mem_children += $mem_runs;
        $floor[$class] = $cell['peak_rss_bytes'] ?? 0;
    }

    foreach ($mem_workloads as $workload) {
        foreach ($mem_sizes as $n) {
            $row = ['workload' => $workload, 'n' => $n, 'arms' => []];
            foreach ([['A', 'array', 'array'], ['B', $b_handles[0], 'judy'], ['C', $c_handles[0], 'judy']] as [$class, $handle, $impl]) {
                $cell = tam_mem_cell($handle, $workload, $impl, $n, $mem_runs, __FILE__);
                $mem_children += $mem_runs;
                if ($cell === null) {
                    $row['arms'][$class] = null;
                    continue;
                }
                $cell['over_floor_bytes'] = max(0, $cell['peak_rss_bytes'] - $floor[$class]);
                $cell['bytes_per_element'] = $n > 0
                    ? round($cell['over_floor_bytes'] / $n, 2)
                    : null;
                $row['arms'][$class] = $cell;
            }
            // Headline memory ratios. >1 means the arm in the numerator uses
            // more memory, so "array / C" above 1 is a php-judy win.
            $a = $row['arms']['A']['over_floor_bytes'] ?? null;
            $b = $row['arms']['B']['over_floor_bytes'] ?? null;
            $c = $row['arms']['C']['over_floor_bytes'] ?? null;
            $row['array_over_bundled'] = ($a !== null && $c) ? round($a / $c, 3) : null;
            $row['system_over_bundled'] = ($b !== null && $c) ? round($b / $c, 3) : null;
            $memory[] = $row;

            if (!$quiet) {
                fwrite(STDERR, sprintf("  memory %-14s n=%-9d A=%-10s B=%-10s C=%-10s  A/C=%s\n",
                    $workload, $n,
                    $a === null ? '-' : tam_fmt_bytes($a),
                    $b === null ? '-' : tam_fmt_bytes($b),
                    $c === null ? '-' : tam_fmt_bytes($c),
                    $row['array_over_bundled'] === null ? '-' : sprintf('%.2fx', $row['array_over_bundled'])));
            }
        }
    }
    $loads[] = tam_load_snapshot('after-memory');
}

$loads[] = tam_load_snapshot('end');
$wall = microtime(true) - $started;

// ── Analysis ────────────────────────────────────────────────────────────────

// Which claim floor applies to this run. The project's pooled controls put it
// at ~3% for cache-resident cells and ~1.3% out-of-cache; the timing suite runs
// at a single --size, so the applicable floor follows that size.
$residency    = $size >= $dram_size ? 'out-of-cache' : 'cache-resident';
$applied_floor = $size >= $dram_size ? $dram_floor : $cache_floor;

/**
 * Per-build breakdown of a paired-ratio series.
 *
 * Rounds rotate the concrete builds, so pair index r was served by system build
 * r % nB and bundled build r % nC. Grouping the ratios by that pair and
 * reporting the spread across groups is what separates a real libJudy effect
 * (every build pair agrees) from a page/cache-layout artifact of one particular
 * binary (one pair carries the whole delta). A cell whose per-build spread is
 * comparable to its own delta is layout noise, not a library change.
 */
function tam_per_build(array $pairs, int $n_b, int $n_c): array
{
    $by = [];
    foreach ($pairs as $r => $ratio) {
        $key = 'B' . (($r % $n_b) + 1) . '/C' . (($r % $n_c) + 1);
        $by[$key][] = $ratio;
    }
    $meds = [];
    foreach ($by as $key => $xs) {
        $meds[$key] = round((tam_median($xs) - 1.0) * 100.0, 2);
    }
    $vals = array_values($meds);
    return [
        'per_build_delta_pct' => $meds,
        'build_spread_pct'    => count($vals) > 1 ? round(max($vals) - min($vals), 2) : 0.0,
        'builds'              => count($meds),
    ];
}

/** Per-round paired ratios of two same-length series. */
function tam_pairs(array $num, array $den): array
{
    $pairs = [];
    $n = min(count($num), count($den));
    for ($r = 0; $r < $n; $r++) {
        if ($den[$r] > 0.0) { $pairs[] = $num[$r] / $den[$r]; }
    }
    return $pairs;
}

// ---- The control: PHP-only work, measured under both arms -------------------
//
// The `.php` rows execute not one instruction of libJudy, so a B-vs-C
// difference on them cannot be a libJudy effect. Their median is this run's own
// measurement of the noise floor, and it re-centres every judy cell.

$control_rows = [];
$control_deltas = [];
foreach ($php_ms['C'] as $id => $cser) {
    // ONLY genuine PHP-array rows may serve as the control. The `.php` arms in
    // api.* and adv.* build into and iterate over Judy instances, so they
    // execute libJudy and carry real B-vs-C signal. Using them here would be
    // self-defeating: a bundled tree that is genuinely faster would speed those
    // rows up too, drag the control median below 1.0, and then dividing by it
    // would subtract the very effect the run exists to measure.
    if (!in_array($id, TAM_ARM_A_ROWS, true)) { continue; }
    $bser = $php_ms['B'][$id] ?? null;
    if ($bser === null) { continue; }
    $pairs = tam_pairs($cser, $bser);
    if (count($pairs) < 3) { continue; }
    if (tam_median($bser) < $min_ms && tam_median($cser) < $min_ms) { continue; }
    $v = tam_verdict($pairs, $applied_floor);
    $control_rows[$id] = $v + ['b_ms' => round(tam_median($bser), 4), 'c_ms' => round(tam_median($cser), 4)];
    $control_deltas[] = tam_median($pairs);
}
$control_median = $control_deltas ? tam_median($control_deltas) : 1.0;
$control_ci     = $control_deltas ? tam_median_ci($control_deltas) : [1.0, 1.0];

// The measured floor: how far the control rows themselves scatter. If this
// exceeds the assumed claim floor, the assumed floor is too optimistic FOR THIS
// RUN and the larger of the two governs.
$control_spread_pct = $control_deltas
    ? max(abs(max($control_deltas) - 1.0), abs(min($control_deltas) - 1.0)) * 100.0
    : 0.0;

$hygiene_failed = false;
$foreign_seen   = false;
foreach ($loads as $l) {
    if ($l['over']) { $hygiene_failed = true; }
    // Co-tenancy is its own failure mode, independent of load average.
    if (!empty($l['foreign_busy'])) { $hygiene_failed = true; $foreign_seen = true; }
}
$contaminated = $hygiene_failed || abs($control_median - 1.0) * 100.0 > $applied_floor;

// ---- B vs C: this project's isolated contribution --------------------------

$bvc = [];
foreach ($judy_ms['C'] as $id => $cser) {
    $bser = $judy_ms['B'][$id] ?? null;
    if ($bser === null) { continue; }
    $pairs = tam_pairs($cser, $bser);
    if (count($pairs) < 3) { continue; }
    $b_med = tam_median($bser);
    $c_med = tam_median($cser);
    if ($b_med < $min_ms && $c_med < $min_ms) { continue; }

    // Re-centre by what the PHP-only control measured, so runner movement
    // divides out while a genuine library-wide shift survives.
    $adj = array_map(fn($p) => $p / $control_median, $pairs);
    $v   = tam_verdict($adj, $applied_floor);

    if ($contaminated && $v['status'] !== 'null') {
        $v['status'] = 'null';
        $v['reason'] = 'suppressed: run flagged contaminated';
    }
    $pb = tam_per_build($adj, count($b_handles), count($c_handles));
    // A delta no larger than the disagreement between build pairs is not a
    // library effect, whatever its CI says.
    if ($v['status'] !== 'null' && $pb['builds'] > 1
        && $pb['build_spread_pct'] > abs($v['delta_pct'])) {
        $v['status'] = 'null';
        $v['reason'] = sprintf('per-build spread %.2f%% exceeds the %.2f%% delta — layout, not libJudy',
            $pb['build_spread_pct'], abs($v['delta_pct']));
    }
    $bvc[$id] = $v + $pb + [
        'system_ms'    => round($b_med, 4),
        'bundled_ms'   => round($c_med, 4),
        'raw_delta_pct'=> round((tam_median($pairs) - 1.0) * 100.0, 2),
        'spread_pct'   => [round(tam_spread_pct($bser), 1), round(tam_spread_pct($cser), 1)],
        // Raw per-round series, so this run can be re-analysed (different floor,
        // different drift model) without paying for the measurement again.
        'system_runs_ms'  => array_map(fn($x) => round($x, 4), $bser),
        'bundled_runs_ms' => array_map(fn($x) => round($x, 4), $cser),
        'paired_ratios'   => array_map(fn($x) => round($x, 5), $pairs),
    ];
}

// ---- A vs C: should you use php-judy at all --------------------------------
//
// Paired inside one process: judy-bench.php measures the array row and the Judy
// row microseconds apart in the same child, so this ratio carries no
// between-process drift at all. Only rows whose `.php` arm is a genuine PHP
// array are eligible (TAM_ARM_A_ROWS); the rest are recorded separately as
// judy-native-method vs PHP-loop-over-Judy, which is a different question.

$avc = [];
$judy_vs_phploop = [];
foreach ($judy_ms['C'] as $id => $cjudy) {
    $carr = $php_ms['C'][$id] ?? null;
    if ($carr === null) { continue; }
    $pairs = tam_pairs($cjudy, $carr);
    if (count($pairs) < 3) { continue; }
    $j_med = tam_median($cjudy);
    $a_med = tam_median($carr);
    if ($j_med < $min_ms && $a_med < $min_ms) { continue; }

    // A vs C is a real magnitude question, not a noise question: a 2x gap is
    // not "inside the floor". Still, apply the floor so near-parity cells are
    // reported as parity rather than as a spurious direction.
    $v = tam_verdict($pairs, $applied_floor);
    $rec = $v + [
        'array_runs_ms' => array_map(fn($x) => round($x, 4), $carr),
        'judy_runs_ms'  => array_map(fn($x) => round($x, 4), $cjudy),
        'array_ms'  => round($a_med, 4),
        'judy_ms'   => round($j_med, 4),
        'judy_over_array' => round($j_med / max($a_med, 1e-9), 3),
        'winner'    => $v['status'] === 'FASTER' ? 'php-judy'
                     : ($v['status'] === 'SLOWER' ? 'php-array' : 'parity'),
    ];
    if (in_array($id, TAM_ARM_A_ROWS, true)) {
        $avc[$id] = $rec;
    } else {
        $judy_vs_phploop[$id] = $rec;
    }
}

// ── Output ──────────────────────────────────────────────────────────────────

$counts = ['faster' => 0, 'slower' => 0, 'null' => 0];
foreach ($bvc as $row) {
    $k = $row['status'] === 'FASTER' ? 'faster' : ($row['status'] === 'SLOWER' ? 'slower' : 'null');
    $counts[$k]++;
}
$a_counts = ['php-judy' => 0, 'php-array' => 0, 'parity' => 0];
foreach ($avc as $row) { $a_counts[$row['winner']]++; }

$result = [
    'metadata' => [
        'schema'              => 'judy-bench-threearm/1',
        'label'               => $label,
        'date'                => date('Y-m-d\TH:i:sP'),
        'php_version'         => PHP_VERSION,
        'platform'            => PHP_OS . ' ' . php_uname('m'),
        'uname'               => php_uname(),
        'judy_version'        => $arm_meta[$c_handles[0]]['judy_version'] ?? ($verify[$c_handles[0]]['version'] ?? null),
        'system_so'           => array_map('realpath', $system_sos),
        'bundled_so'          => array_map('realpath', $bundled_sos),
        'system_so_sha256'    => $b_hashes,
        'bundled_so_sha256'   => $c_hashes,
        'system_provenance'   => $opts['system-provenance']  ?? null,
        'bundled_provenance'  => $opts['bundled-provenance'] ?? null,
        'same_source_asserted'=> isset($opts['assert-same-source']),
        'identical_builds'    => $identical,
        'is_control_run'      => $identical,
        'size'                => $size,
        'iterations'          => $iterations,
        'rounds'              => $rounds,
        'groups'              => $groups,
        'mem_sizes'           => $mem_sizes,
        'mem_workloads'       => $mem_workloads,
        'mem_runs'            => $mem_runs,
        'min_ms'              => $min_ms,
        'residency'           => $residency,
        'claim_floor_pct'     => $applied_floor,
        'cache_floor_pct'     => $cache_floor,
        'dram_floor_pct'      => $dram_floor,
        'timing_children'     => $timing_children,
        'memory_children'     => $mem_children,
        'wall_seconds'        => round($wall, 1),
    ],
    'hygiene' => [
        'snapshots'      => $loads,
        'failed'         => $hygiene_failed,
        'foreign_tenant' => $foreign_seen,
        'contaminated'   => $contaminated,
        'note'           => 'load average alone is necessary but not sufficient: a co-resident '
                          . 'memory-bound benchmark contends for LLC and memory bandwidth at low load, '
                          . 'and a PHP-array control does not detect it',
    ],
    'control' => [
        'php_only' => [
            'median_ratio'      => round($control_median, 5),
            'delta_pct'         => round(($control_median - 1.0) * 100.0, 2),
            'ci_delta_pct'      => [round(($control_ci[0] - 1.0) * 100.0, 2), round(($control_ci[1] - 1.0) * 100.0, 2)],
            'measured_spread_pct' => round($control_spread_pct, 2),
            'row_count'         => count($control_rows),
            'eligibility'       => 'genuine PHP-array rows only (TAM_ARM_A_ROWS); '
                                 . 'Judy-touching .php rows are excluded because they carry real signal',
            'rows'              => $control_rows,
        ],
        'rebuild_control_run' => $identical,
    ],
    'counts'          => $counts,
    'a_counts'        => $a_counts,
    'bundled_vs_system' => $bvc,
    'array_vs_bundled'  => $avc,
    'judy_vs_phploop'   => $judy_vs_phploop,
    'memory'            => $memory,
    'verify'            => $verify,
];

file_put_contents($out_file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// ---- Human table -----------------------------------------------------------

if (!$quiet) {
    $line = str_repeat('-', 96);
    echo "\n$line\n";
    echo "php-judy three-arm benchmark — $label\n";
    echo $line . "\n";
    printf("  PHP %s, %s\n", PHP_VERSION, php_uname('s') . ' ' . php_uname('m'));
    printf("  arm B system libJudy : %s\n", $opts['system-provenance'] ?? '(provenance NOT recorded — B is uninterpretable)');
    printf("  arm C bundled libJudy: %s\n", $opts['bundled-provenance'] ?? 'vendored libjudy/ tree');
    printf("  size %d (%s), %d rounds, %d iterations, floor %.1f%%\n",
        $size, $residency, $rounds, $iterations, $applied_floor);
    printf("  same-source attested : %s\n", isset($opts['assert-same-source']) ? 'yes' : 'NO — delta not interpretable');
    if ($identical) {
        echo "  *** CONTROL RUN: both arms are the same build. Every cell should read null. ***\n";
    }

    echo "\n  host hygiene\n";
    foreach ($loads as $l) {
        printf("    %-14s load1=%-6s cpus=%-4s threshold=%-5s foreign=%5.1f%% %s\n",
            $l['phase'], $l['load1'] === null ? '?' : $l['load1'],
            $l['cpus'] ?? '?', $l['threshold'] === null ? '?' : sprintf('%.1f', $l['threshold']),
            $l['foreign_cpu_pct'],
            $l['over'] ? '*** LOAD OVER THRESHOLD ***' : (!empty($l['foreign_busy']) ? '*** FOREIGN TENANT ***' : 'ok'));
        foreach ($l['heavy'] as $h) {
            printf("      foreign: pid=%-8d %5.1f%% %s\n", $h['pid'], $h['cpu_pct'], $h['cmd']);
        }
    }

    printf("\n  control (PHP-only rows, touch no libJudy): %+.2f%% [%+.2f, %+.2f] over %d rows\n",
        ($control_median - 1.0) * 100.0,
        ($control_ci[0] - 1.0) * 100.0, ($control_ci[1] - 1.0) * 100.0, count($control_rows));
    printf("  measured control scatter: %.2f%%  (assumed floor %.1f%%)\n", $control_spread_pct, $applied_floor);
    if ($contaminated) {
        echo "  *** RUN FLAGGED CONTAMINATED — all verdicts suppressed to null ***\n";
    }

    if ($memory) {
        echo "\n$line\n  MEMORY — peak RSS over per-arm empty-process floor (the headline axis)\n$line\n";
        printf("  %-14s %10s %12s %12s %12s %9s %9s\n",
            'workload', 'n', 'A array', 'B system', 'C bundled', 'A/C', 'B/C');
        foreach ($memory as $row) {
            printf("  %-14s %10d %12s %12s %12s %9s %9s\n",
                $row['workload'], $row['n'],
                isset($row['arms']['A']) ? tam_fmt_bytes($row['arms']['A']['over_floor_bytes']) : '-',
                isset($row['arms']['B']) ? tam_fmt_bytes($row['arms']['B']['over_floor_bytes']) : '-',
                isset($row['arms']['C']) ? tam_fmt_bytes($row['arms']['C']['over_floor_bytes']) : '-',
                $row['array_over_bundled']  === null ? '-' : sprintf('%.2fx', $row['array_over_bundled']),
                $row['system_over_bundled'] === null ? '-' : sprintf('%.2fx', $row['system_over_bundled']));
        }
        echo "  A/C above 1.00 means the PHP array uses that many times more memory than php-judy.\n";
    }

    if ($bvc) {
        echo "\n$line\n  B vs C — bundled libJudy against system libJudy (this project's contribution)\n";
        echo "  ratio < 1 means the bundled tree is faster. Only rows whose whole CI clears\n";
        printf("  the %.1f%% floor assert anything; everything else is null.\n$line\n", $applied_floor);
        printf("  %-42s %9s %9s %8s %-18s %s\n", 'benchmark', 'system', 'bundled', 'delta', 'CI', 'verdict');
        uasort($bvc, fn($x, $y) => $x['delta_pct'] <=> $y['delta_pct']);
        foreach ($bvc as $id => $row) {
            printf("  %-42s %9.2f %9.2f %7.2f%% [%+6.2f,%+6.2f] %s\n",
                $id, $row['system_ms'], $row['bundled_ms'], $row['delta_pct'],
                $row['ci_delta_pct'][0], $row['ci_delta_pct'][1],
                $row['status'] === 'FASTER' ? 'FASTER' : ($row['status'] === 'SLOWER' ? 'SLOWER' : 'null'));
        }
        printf("  totals: %d faster, %d slower, %d null\n", $counts['faster'], $counts['slower'], $counts['null']);
    }

    if ($avc) {
        echo "\n$line\n  A vs C — PHP native array against php-judy (bundled)\n";
        echo "  Only rows whose PHP arm is a genuine PHP array. judy/array above 1.00 means\n";
        echo "  the PHP array is FASTER — publish these, they are the honest half of the story.\n$line\n";
        printf("  %-42s %9s %9s %9s %s\n", 'benchmark', 'array ms', 'judy ms', 'judy/arr', 'winner');
        uasort($avc, fn($x, $y) => $x['judy_over_array'] <=> $y['judy_over_array']);
        foreach ($avc as $id => $row) {
            printf("  %-42s %9.2f %9.2f %8.2fx %s\n",
                $id, $row['array_ms'], $row['judy_ms'], $row['judy_over_array'], $row['winner']);
        }
        printf("  totals: php-judy wins %d, PHP array wins %d, parity %d\n",
            $a_counts['php-judy'], $a_counts['php-array'], $a_counts['parity']);
    }

    echo "\nWrote $out_file\n";
}

exit(0);
