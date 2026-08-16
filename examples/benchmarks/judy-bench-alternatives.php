<?php
/**
 * Judy versus the alternatives people actually choose.
 *
 * The rest of this suite compares Judy against a native PHP array, which is
 * the right "when NOT to use Judy" guide. But nobody picks Judy *instead of*
 * an array — they pick it instead of APCu, SplFixedArray, or a hand-rolled
 * sorted-array index. This harness measures those four head-to-heads:
 *
 *   prefix    Prefix/namespace invalidation as a function of total cache size.
 *             Judy trie walk vs APCu's APCuIterator regex scan vs array scan.
 *             The claim under test is a complexity-class difference, so this
 *             one sweeps sizes and emits a curve, not a single number.
 *   presence  Membership/dedup sets at 1M-10M IDs: Judy BITSET vs PHP array
 *             vs SplFixedArray vs APCu. Metrics are peak RSS and throughput.
 *   window    Sliding-window eviction: Judy deleteRange() vs array scan and
 *             array_filter(), as a function of the RETAINED set size.
 *   floor     Floor / CIDR lookup: Judy last() vs a sorted array + userland
 *             binary search. Lookup latency AND build/update cost.
 *
 * ── Read this before quoting any number ──────────────────────────────────
 *
 * 1. APCu is shared across every PHP-FPM worker in a pool. Judy is per
 *    process: each worker pays its own memory and fills its own cache. A
 *    latency win here does NOT mean "replace APCu" — for a shared read-mostly
 *    cache across many short-lived workers, APCu is solving a problem Judy
 *    does not currently solve at all. Judy fits long-lived workers (Swoole,
 *    RoadRunner, FrankenPHP, CLI consumers) and per-request indexes.
 * 2. In CLI mode APCu's segment is per process too, so the numbers below are
 *    single-process on BOTH sides — a fair latency comparison, and precisely
 *    the setting in which APCu's real advantage (sharing) is invisible.
 * 3. Peak RSS is read from getrusage()['ru_maxrss'] in a dedicated child
 *    process, because Judy allocates outside PHP's memory manager and
 *    memory_get_usage() cannot see it. APCu's number includes the shared
 *    segment's touched pages, so treat its memory column as approximate.
 * 4. Redis/Memcached are deliberately absent. Their numbers would include
 *    IPC or network cost against an in-process structure, which is not a
 *    head-to-head comparison; presenting it as one would be dishonest.
 *
 * ── Methodology ──────────────────────────────────────────────────────────
 *
 * Every cell runs in a fresh child process, R times (default 7). Reported as
 * median with a 95% percentile-bootstrap CI over the runs. Two backends are
 * only called separated when their CIs do not overlap; otherwise the harness
 * prints "no measured separation" rather than a delta.
 *
 * Machine load contaminates every timing here. Check load average before and
 * between runs; on a contended box treat all timings as upper bounds.
 *
 * Usage:
 *   php examples/benchmarks/judy-bench-alternatives.php [workload] [runs]
 *
 *     workload  all (default) | prefix | presence | window | floor
 *     runs      repetitions per cell (default 7)
 *
 *   APCu rows are skipped with a notice when ext-apcu is missing or
 *   apc.enable_cli is off; the rest of the suite still runs.
 *
 * Examples:
 *   php examples/benchmarks/judy-bench-alternatives.php prefix 9
 *   php -d apc.enable_cli=1 -d apc.shm_size=1024M \
 *       examples/benchmarks/judy-bench-alternatives.php
 */

// ─────────────────────────────────────────────────────────────────────────
// Child-process entry point: run exactly one cell, print JSON, exit.
// ─────────────────────────────────────────────────────────────────────────

const PREFIX_GROUP   = 10;      // keys per invalidation group
const PREFIX_REPEATS = 5;       // groups invalidated per timed run
const WINDOW_EXPIRED = 10_000;  // expired buckets, held constant across the sweep
const FLOOR_PROBES   = 100_000; // lookups per timed run
const FLOOR_UPDATES  = 1_000;   // range inserts per timed run

if (($argv[1] ?? null) === '--child') {
    exit(runChild($argv[2], $argv[3], (int) $argv[4], (int) ($argv[5] ?? 0)));
}

/** Peak resident set size in bytes. ru_maxrss is bytes on macOS, KB on Linux. */
function peakRss(): int
{
    return getrusage()['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024);
}

function emit(array $row): int
{
    echo json_encode($row + ['peak_rss' => peakRss()]), "\n";

    return 0;
}

function runChild(string $workload, string $impl, int $n, int $arg): int
{
    if (str_starts_with($impl, 'judy') && !extension_loaded('judy')) {
        fwrite(STDERR, "judy extension not loaded\n");

        return 1;
    }
    if ($impl === 'apcu' && !apcuUsable()) {
        fwrite(STDERR, "apcu not usable\n");

        return 1;
    }

    return match ($workload) {
        'prefix'   => childPrefix($impl, $n),
        'presence' => childPresence($impl, $n, $arg),
        'window'   => childWindow($impl, $n),
        'floor'    => childFloor($impl, $n),
        default    => 1,
    };
}

function apcuUsable(): bool
{
    return extension_loaded('apcu')
        && function_exists('apcu_store')
        && (bool) ini_get('apc.enable_cli');
}

// ── Workload 1: prefix invalidation ──────────────────────────────────────
//
// n entries keyed user.<uid>.item.<i>, PREFIX_GROUP items per uid. Invalidate
// PREFIX_REPEATS whole uids and report the mean per-group cost. The group
// being dropped is a CONSTANT size at every n, so any growth in the reported
// latency is growth in the cost of *finding* the group, which is the whole
// question.

function prefixKey(int $i): string
{
    return sprintf('user.%d.item.%d', intdiv($i, PREFIX_GROUP), $i % PREFIX_GROUP);
}

function childPrefix(string $impl, int $n): int
{
    $uids    = intdiv($n, PREFIX_GROUP);
    $targets = [];
    for ($r = 0; $r < PREFIX_REPEATS; $r++) {
        // Spread the targets through the keyspace so no backend benefits from
        // hitting the same trie path or hash bucket repeatedly.
        $targets[] = (int) (($r + 0.5) * $uids / PREFIX_REPEATS);
    }

    $value = static fn (int $i): array => ['id' => $i, 'score' => $i * 3];

    switch ($impl) {
        case 'judy':
        case 'judy-hash':
            $type = $impl === 'judy' ? Judy::STRING_TO_MIXED : Judy::STRING_TO_MIXED_HASH;
            $c    = new Judy($type);
            for ($i = 0; $i < $n; $i++) {
                $c[prefixKey($i)] = $value($i);
            }
            $t0      = hrtime(true);
            $deleted = 0;
            foreach ($targets as $uid) {
                $deleted += $impl === 'judy'
                    ? judyDeletePrefix($c, "user.$uid.")
                    : hashDeletePrefix($c, "user.$uid.");
            }
            $elapsed = hrtime(true) - $t0;
            break;

        case 'apcu':
            apcu_clear_cache();
            for ($i = 0; $i < $n; $i++) {
                apcu_store(prefixKey($i), $value($i));
            }
            if (apcu_cache_info(true)['num_entries'] < $n) {
                fwrite(STDERR, "apcu could not hold $n entries (raise apc.shm_size)\n");

                return 1;
            }
            $before = apcu_cache_info(true)['num_entries'];
            $t0     = hrtime(true);
            foreach ($targets as $uid) {
                // The scan is what you do when you cannot enumerate the group's
                // keys. (If you can enumerate them, apcu_delete(array) is O(k)
                // and this workload does not apply to you.)
                $it = new APCuIterator('/^user\.' . $uid . '\./', APC_ITER_KEY);
                apcu_delete($it);
            }
            $elapsed = hrtime(true) - $t0;
            // apcu_delete(APCuIterator) returns bool, so count outside the timer.
            $deleted = $before - apcu_cache_info(true)['num_entries'];
            break;

        default: // plain PHP array
            $c = [];
            for ($i = 0; $i < $n; $i++) {
                $c[prefixKey($i)] = $value($i);
            }
            $t0      = hrtime(true);
            $deleted = 0;
            foreach ($targets as $uid) {
                $p = "user.$uid.";
                foreach (array_keys($c) as $k) {
                    if (str_starts_with($k, $p)) {
                        unset($c[$k]);
                        $deleted++;
                    }
                }
            }
            $elapsed = hrtime(true) - $t0;
    }

    return emit([
        'metric'  => $elapsed / 1e3 / PREFIX_REPEATS, // µs per group invalidation
        'deleted' => $deleted,
    ]);
}

/** Ordered trie walk: seek to the prefix, stop at the first non-match. */
function judyDeletePrefix(Judy $store, string $prefix): int
{
    $deleted = 0;
    $key     = $store->first($prefix);
    while ($key !== null && str_starts_with($key, $prefix)) {
        $next = $store->searchNext($key);
        unset($store[$key]);
        $deleted++;
        $key = $next;
    }

    return $deleted;
}

/** Unordered hash type: no adjacency, so the whole keyset must be tested. */
function hashDeletePrefix(Judy $store, string $prefix): int
{
    $deleted = 0;
    foreach ($store->keys() as $key) {
        if (str_starts_with($key, $prefix)) {
            unset($store[$key]);
            $deleted++;
        }
    }

    return $deleted;
}

// ── Workload 2: presence / dedup at scale ────────────────────────────────
//
// n IDs drawn from a keyspace of n * $spread. spread=1 is a dense ID set,
// spread=8 is the sparse case (real user/object IDs with gaps). Density is
// the dominant variable for BITSET and the only variable that makes
// SplFixedArray viable or not, so it is a reported dimension, not a constant.

function presenceIds(int $n, int $spread): Generator
{
    mt_srand(42);
    $space = $n * $spread;
    if ($spread === 1) {
        for ($i = 0; $i < $n; $i++) {
            yield $i;
        }

        return;
    }
    for ($i = 0; $i < $n; $i++) {
        yield mt_rand(0, $space - 1);
    }
}

function childPresence(string $impl, int $n, int $spread): int
{
    if ($impl === 'baseline') {
        // Empty run: establishes the interpreter's own RSS so every other row
        // in the table can be read as "floor + what the structure costs".
        return emit(['metric' => 0.0, 'lookup_kops' => 0.0, 'hits' => 0]);
    }

    $space = $n * max(1, $spread);

    $t0 = hrtime(true);
    switch ($impl) {
        case 'judy':
            $set = new Judy(Judy::BITSET);
            foreach (presenceIds($n, $spread) as $id) {
                $set[$id] = true;
            }
            break;
        case 'splfixed':
            $set = new SplFixedArray($space);
            foreach (presenceIds($n, $spread) as $id) {
                $set[$id] = true;
            }
            break;
        case 'apcu':
            apcu_clear_cache();
            foreach (presenceIds($n, $spread) as $id) {
                apcu_store("id:$id", 1);
            }
            break;
        default:
            $set = [];
            foreach (presenceIds($n, $spread) as $id) {
                $set[$id] = true;
            }
    }
    $tInsert = hrtime(true) - $t0;

    // Probe: half hits (ids we inserted), half misses (odd offsets outside the
    // inserted set for spread=1; random draws otherwise).
    $probes = min($n, 1_000_000);
    mt_srand(1337);
    $hits = 0;
    $t0   = hrtime(true);
    switch ($impl) {
        case 'judy':
        case 'splfixed':
            for ($i = 0; $i < $probes; $i++) {
                $id = mt_rand(0, $space - 1);
                if (isset($set[$id])) {
                    $hits++;
                }
            }
            break;
        case 'apcu':
            for ($i = 0; $i < $probes; $i++) {
                $id = mt_rand(0, $space - 1);
                if (apcu_exists("id:$id")) {
                    $hits++;
                }
            }
            break;
        default:
            for ($i = 0; $i < $probes; $i++) {
                $id = mt_rand(0, $space - 1);
                if (isset($set[$id])) {
                    $hits++;
                }
            }
    }
    $tLookup = hrtime(true) - $t0;

    return emit([
        'metric'      => $n / ($tInsert / 1e9) / 1000, // insert kops/s (primary)
        'lookup_kops' => $probes / ($tLookup / 1e9) / 1000,
        'hits'        => $hits,
    ]);
}

// ── Workload 3: sliding-window eviction ──────────────────────────────────
//
// WINDOW_EXPIRED buckets have aged out; $n buckets are retained. The expired
// slice is CONSTANT, so the reported cost is purely a function of how much
// the implementation has to touch that it is going to keep.

function childWindow(string $impl, int $n): int
{
    $cutoff = WINDOW_EXPIRED - 1;
    $total  = WINDOW_EXPIRED + $n;

    switch ($impl) {
        case 'judy':
            $w = new Judy(Judy::INT_TO_INT);
            for ($i = 0; $i < $total; $i++) {
                $w[$i] = 1;
            }
            $t0      = hrtime(true);
            $evicted = $w->deleteRange(0, $cutoff);
            $elapsed = hrtime(true) - $t0;
            $left    = count($w);
            break;

        case 'array-filter':
            $w = [];
            for ($i = 0; $i < $total; $i++) {
                $w[$i] = 1;
            }
            $t0      = hrtime(true);
            $w       = array_filter($w, static fn ($k) => $k > $cutoff, ARRAY_FILTER_USE_KEY);
            $elapsed = hrtime(true) - $t0;
            $evicted = $total - count($w);
            $left    = count($w);
            break;

        default: // array-scan: the hand-written foreach most code actually has
            $w = [];
            for ($i = 0; $i < $total; $i++) {
                $w[$i] = 1;
            }
            $t0      = hrtime(true);
            $evicted = 0;
            foreach (array_keys($w) as $k) {
                if ($k <= $cutoff) {
                    unset($w[$k]);
                    $evicted++;
                }
            }
            $elapsed = hrtime(true) - $t0;
            $left    = count($w);
    }

    return emit([
        'metric'  => $elapsed / 1e3, // µs for one eviction pass
        'evicted' => (int) $evicted,
        'left'    => $left,
    ]);
}

// ── Workload 4: floor / CIDR lookup ──────────────────────────────────────
//
// n non-overlapping ranges keyed by start address. Resolve an address to its
// range: the greatest start <= addr. Judy does it with last(); the honest
// alternative is a sorted array of starts plus a userland binary search.
//
// This is the row most likely to go against Judy, and it reports build and
// update cost precisely because that is where the two diverge most.

function floorStarts(int $n): array
{
    // Ranges of width 16-271, laid out in ascending order with gaps.
    mt_srand(7);
    $starts = [];
    $ends   = [];
    $addr   = 0;
    for ($i = 0; $i < $n; $i++) {
        $width    = 16 + mt_rand(0, 255);
        $starts[] = $addr;
        $ends[]   = $addr + $width - 1;
        $addr    += $width + mt_rand(0, 32);
    }

    return [$starts, $ends, $addr];
}

function childFloor(string $impl, int $n): int
{
    [$starts, $ends, $span] = floorStarts($n);

    // Build.
    $t0 = hrtime(true);
    if ($impl === 'judy') {
        $r = new Judy(Judy::INT_TO_MIXED);
        for ($i = 0; $i < $n; $i++) {
            $r[$starts[$i]] = [$ends[$i], $i];
        }
    } else {
        // Ranges arrive unsorted in the real world; the sorted-array index has
        // to pay for ordering them. Judy's insert pays for it incrementally.
        $sStart = $starts;
        $sEnd   = $ends;
        array_multisort($sStart, $sEnd);
    }
    $tBuild = hrtime(true) - $t0;

    // Lookup.
    mt_srand(99);
    $matched = 0;
    $t0      = hrtime(true);
    if ($impl === 'judy') {
        for ($p = 0; $p < FLOOR_PROBES; $p++) {
            $addr  = mt_rand(0, $span - 1);
            $start = $r->last($addr);
            if ($start !== null && $r[$start][0] >= $addr) {
                $matched++;
            }
        }
    } else {
        $hi0 = $n - 1;
        for ($p = 0; $p < FLOOR_PROBES; $p++) {
            $addr = mt_rand(0, $span - 1);
            $lo   = 0;
            $hi   = $hi0;
            $found = -1;
            while ($lo <= $hi) {
                $mid = ($lo + $hi) >> 1;
                if ($sStart[$mid] <= $addr) {
                    $found = $mid;
                    $lo    = $mid + 1;
                } else {
                    $hi = $mid - 1;
                }
            }
            if ($found >= 0 && $sEnd[$found] >= $addr) {
                $matched++;
            }
        }
    }
    $tLookup = hrtime(true) - $t0;

    // Incremental update: insert FLOOR_UPDATES new ranges, keeping the index
    // queryable. Judy takes the write directly; the sorted array must splice.
    mt_srand(123);
    $t0 = hrtime(true);
    if ($impl === 'judy') {
        for ($u = 0; $u < FLOOR_UPDATES; $u++) {
            $addr     = mt_rand(0, $span - 1);
            $r[$addr] = [$addr + 8, -1];
        }
    } else {
        for ($u = 0; $u < FLOOR_UPDATES; $u++) {
            $addr = mt_rand(0, $span - 1);
            $lo   = 0;
            $hi   = count($sStart) - 1;
            while ($lo <= $hi) {
                $mid = ($lo + $hi) >> 1;
                if ($sStart[$mid] < $addr) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid - 1;
                }
            }
            array_splice($sStart, $lo, 0, [$addr]);
            array_splice($sEnd, $lo, 0, [$addr + 8]);
        }
    }
    $tUpdate = hrtime(true) - $t0;

    return emit([
        'metric'    => $tLookup / FLOOR_PROBES,          // ns per lookup (primary)
        'build_ms'  => $tBuild / 1e6,
        'update_us' => $tUpdate / 1e3 / FLOOR_UPDATES,
        'matched'   => $matched,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────
// Parent: statistics
// ─────────────────────────────────────────────────────────────────────────

function median(array $xs): float
{
    sort($xs);
    $c = count($xs);

    return $c % 2 ? (float) $xs[intdiv($c, 2)] : ($xs[$c / 2 - 1] + $xs[$c / 2]) / 2;
}

/**
 * 95% percentile-bootstrap CI for the median.
 *
 * A single run is not a measurement; a point estimate without a CI cannot be
 * compared against another point estimate. Seeded so re-deriving the stats
 * from the same samples reproduces the same interval.
 *
 * @return array{0:float,1:float}
 */
function medianCi(array $xs, int $resamples = 2000): array
{
    $n = count($xs);
    if ($n < 3) {
        return [(float) min($xs), (float) max($xs)];
    }
    mt_srand(20260815);
    $meds = [];
    for ($b = 0; $b < $resamples; $b++) {
        $s = [];
        for ($i = 0; $i < $n; $i++) {
            $s[] = $xs[mt_rand(0, $n - 1)];
        }
        $meds[] = median($s);
    }
    sort($meds);

    return [$meds[(int) floor(0.025 * $resamples)], $meds[(int) ceil(0.975 * $resamples) - 1]];
}

/** Two cells separate only when their CIs do not overlap. */
function separated(array $a, array $b): bool
{
    return $a[1] < $b[0] || $b[1] < $a[0];
}

// ─────────────────────────────────────────────────────────────────────────
// Parent: execution
// ─────────────────────────────────────────────────────────────────────────

$workload = $argv[1] ?? 'all';
$runs     = max(3, (int) ($argv[2] ?? 7));

if (!extension_loaded('judy')) {
    fwrite(STDERR, "The judy extension is not loaded. Run with:\n");
    fwrite(STDERR, "  php -d extension=\$PWD/modules/judy.so " . basename(__FILE__) . "\n");
    exit(1);
}

$apcu = apcuUsable();
$self = escapeshellarg(__FILE__);
$ini  = '-d memory_limit=-1';

// Children must load the same extension the parent did. When judy came from
// `-d extension=...` rather than php.ini, that flag is not inherited, so pass
// it along: $JUDY_SO if set (same convention as examples/dedup-large-stream.php),
// otherwise the module built in this checkout.
if (trim((string) shell_exec(PHP_BINARY . ' -r "echo (int)extension_loaded(\'judy\');"')) !== '1') {
    $so = getenv('JUDY_SO') ?: __DIR__ . '/../../modules/judy.so';
    if (!is_file($so)) {
        fwrite(STDERR, "Cannot find judy.so for child processes. Set JUDY_SO=/path/to/judy.so\n");
        exit(1);
    }
    $ini .= ' -d extension=' . escapeshellarg($so);
}
if (extension_loaded('apcu')) {
    // Child processes need the same APCu settings; a CLI child gets its own
    // segment, which is why these numbers are single-process on both sides.
    $ini .= ' -d apc.enable_cli=1';
    $shm = (string) ini_get('apc.shm_size');
    if ($shm !== '') {
        $ini .= ' -d apc.shm_size=' . escapeshellarg($shm);
    }
}

/**
 * Run one cell $runs times. Returns null (and warns once) if the child fails.
 *
 * @return array{metric:array<float>,rss:array<float>,extra:array<string,array<float>>}|null
 */
function cell(string $workload, string $impl, int $n, int $arg, int $runs): ?array
{
    global $self, $ini;

    $metric = [];
    $rss    = [];
    $extra  = [];
    for ($r = 0; $r < $runs; $r++) {
        $cmd = PHP_BINARY . " $ini $self --child $workload $impl $n $arg 2>/dev/null";
        $j   = json_decode(trim((string) shell_exec($cmd)), true);
        if (!is_array($j) || !isset($j['metric'])) {
            return null;
        }
        $metric[] = (float) $j['metric'];
        $rss[]    = $j['peak_rss'] / 1048576;
        foreach ($j as $k => $v) {
            if ($k !== 'metric' && $k !== 'peak_rss' && is_numeric($v)) {
                $extra[$k][] = (float) $v;
            }
        }
    }

    return ['metric' => $metric, 'rss' => $rss, 'extra' => $extra];
}

function fmtCi(array $ci, string $unit = ''): string
{
    $f = static fn (float $v): string => $v >= 1000
        ? number_format($v, 0)
        : ($v >= 10 ? number_format($v, 1) : number_format($v, 2));

    return '[' . $f($ci[0]) . '..' . $f($ci[1]) . ']' . $unit;
}

/** Horizontal log-scale ASCII bars — a committed plot beats a binary image. */
function asciiBars(array $series, string $unit, int $width = 46): array
{
    $max = 0.0;
    $min = INF;
    foreach ($series as $rows) {
        foreach ($rows as $v) {
            $max = max($max, $v);
            $min = min($min, $v);
        }
    }
    if ($max <= 0) {
        return [];
    }
    $lo    = log10(max($min, $max / 1e6));
    $hi    = log10($max);
    $range = max($hi - $lo, 0.5);

    $out = [];
    foreach ($series as $label => $rows) {
        $out[] = $label;
        foreach ($rows as $name => $v) {
            $len = (int) round($width * (log10(max($v, 1e-9)) - $lo) / $range);
            $len = max(1, min($width, $len));
            $out[] = sprintf(
                '    %-12s %-' . $width . 's %s%s',
                $name,
                str_repeat('#', $len),
                $v >= 1000 ? number_format($v, 0) : number_format($v, 1),
                $unit,
            );
        }
        $out[] = '';
    }
    $out[] = sprintf('    (log scale, %s .. %s%s)', number_format(10 ** $lo, 1), number_format($max, 1), $unit);

    return $out;
}

echo "# Judy vs the alternatives\n\n";
printf(
    "PHP %s | judy %s | apcu %s | %s %s | %d runs/cell, median [95%% bootstrap CI]\n",
    PHP_VERSION,
    judy_version(),
    $apcu ? phpversion('apcu') : 'ABSENT',
    PHP_OS,
    php_uname('m'),
    $runs,
);
if (!$apcu) {
    echo "\n";
    echo "NOTE: APCu rows are skipped. ext-apcu is " .
        (extension_loaded('apcu') ? "loaded but apc.enable_cli is off" : "not installed") . ".\n";
    echo "      Install it and re-run with -d apc.enable_cli=1 -d apc.shm_size=1024M\n";
    echo "      for the APCu comparison; everything else below still runs.\n";
}
echo "\n";
echo "APCu is shared across every PHP-FPM worker in a pool; Judy is per process.\n";
echo "These numbers compare latency in one process and say nothing about that gap.\n";

$want = static fn (string $w): bool => $workload === 'all' || $workload === $w;

// ── 1. Prefix invalidation ───────────────────────────────────────────────

if ($want('prefix')) {
    $sizes  = [10_000, 30_000, 100_000, 300_000, 1_000_000];
    $impls  = ['judy', 'judy-hash', 'array'];
    if ($apcu) {
        $impls[] = 'apcu';
    }

    echo "\n\n## 1. Prefix invalidation vs total cache size\n\n";
    printf(
        "Drop one %d-key group (`user.<uid>.*`) from a store of n entries.\n",
        PREFIX_GROUP,
    );
    echo "The group size is constant at every n, so growth in this column is\n";
    echo "growth in the cost of FINDING the group.\n\n";
    printf("| %-9s | %-24s | %-24s | %-24s | %-24s |\n", 'n', 'judy (µs)', 'judy-hash (µs)', 'array (µs)', 'apcu (µs)');
    echo "|-----------|--------------------------|--------------------------|--------------------------|--------------------------|\n";

    $curve = [];
    $cis   = [];
    foreach ($sizes as $n) {
        $cols = [];
        foreach (['judy', 'judy-hash', 'array', 'apcu'] as $impl) {
            if (!in_array($impl, $impls, true)) {
                $cols[] = 'n/a';
                continue;
            }
            $c = cell('prefix', $impl, $n, 0, $runs);
            if ($c === null) {
                $cols[] = 'FAILED';
                continue;
            }
            $m  = median($c['metric']);
            $ci = medianCi($c['metric']);
            $curve[number_format($n) . ' entries'][$impl] = $m;
            $cis[$n][$impl] = $ci;
            $cols[] = sprintf('%s %s', $m >= 100 ? number_format($m, 0) : number_format($m, 2), fmtCi($ci));
        }
        printf("| %-9s | %-24s | %-24s | %-24s | %-24s |\n", number_format($n), ...$cols);
    }

    echo "\n";
    foreach (asciiBars($curve, 'µs') as $line) {
        echo $line, "\n";
    }

    // Gate: publish the complexity-class claim only if the CIs separate at
    // every size in the sweep.
    echo "\n### Separation check (CI lower bound, every size in the sweep)\n\n";
    foreach (['array', 'apcu', 'judy-hash'] as $rival) {
        if (!in_array($rival, $impls, true)) {
            continue;
        }
        $allSep = true;
        $ratios = [];
        foreach ($sizes as $n) {
            if (!isset($cis[$n]['judy'], $cis[$n][$rival])) {
                $allSep = false;
                continue;
            }
            if (!separated($cis[$n]['judy'], $cis[$n][$rival])) {
                $allSep = false;
            }
            $ratios[] = $curve[number_format($n) . ' entries'][$rival]
                / max($curve[number_format($n) . ' entries']['judy'], 1e-9);
        }
        if (!$ratios) {
            continue;
        }
        printf(
            "- judy vs %-10s %s — advantage %.0fx at n=%s, %.0fx at n=%s%s\n",
            $rival . ':',
            $allSep ? 'SEPARATED at every size' : 'NOT separated at every size',
            $ratios[0],
            number_format($sizes[0]),
            end($ratios),
            number_format(end($sizes)),
            $allSep ? '' : ' (do not publish a delta for the overlapping sizes)',
        );
    }
    echo "\nScaling of each backend across the sweep (last/first, n grew "
        . (int) (end($sizes) / $sizes[0]) . "x):\n";
    foreach ($impls as $impl) {
        $first = $curve[number_format($sizes[0]) . ' entries'][$impl] ?? null;
        $last  = $curve[number_format(end($sizes)) . ' entries'][$impl] ?? null;
        if ($first === null || $last === null) {
            continue;
        }
        printf("- %-10s %.1fx  (%s)\n", $impl, $last / $first, $last / $first < 3 ? 'flat' : 'grows with cache size');
    }
}

// ── 2. Presence / dedup ──────────────────────────────────────────────────

if ($want('presence')) {
    // [n, spread] — spread 1 is a dense ID set, 8 is sparse with gaps.
    $cells = [[1_000_000, 1], [1_000_000, 8], [10_000_000, 1]];

    // Measure the interpreter's own RSS so the memory column is readable as
    // "floor + structure" rather than an opaque absolute number.
    $base  = cell('presence', 'baseline', 0, 0, 3);
    $floor = $base === null ? 0.0 : median($base['rss']);

    echo "\n\n## 2. Presence / dedup sets\n\n";
    echo "Peak RSS is the whole process, from getrusage() in a child, because Judy\n";
    echo "allocates outside PHP's memory manager. The 'over floor' column subtracts\n";
    printf("the measured empty-interpreter baseline (%.1f MB).\n", $floor);
    echo "SplFixedArray must allocate the whole key space, so it tracks density, not\n";
    echo "element count. APCu is skipped at 10M (its shm segment cannot hold it).\n\n";
    printf(
        "| %-12s | %-9s | %-13s | %-14s | %-19s | %-19s |\n",
        'cell', 'impl', 'peak RSS (MB)', 'over floor', 'insert kops/s', 'lookup kops/s',
    );
    echo "|--------------|-----------|---------------|----------------|---------------------|---------------------|\n";

    foreach ($cells as [$n, $spread]) {
        $label = sprintf('%sM %s', $n / 1_000_000, $spread === 1 ? 'dense' : 'sparse');
        $impls = ['judy', 'array', 'splfixed'];
        if ($apcu && $n <= 1_000_000) {
            $impls[] = 'apcu';
        }
        foreach ($impls as $impl) {
            $c = cell('presence', $impl, $n, $spread, $runs);
            if ($c === null) {
                printf("| %-12s | %-9s | %-74s |\n", $label, $impl, 'FAILED (memory limit? shm size?)');
                continue;
            }
            printf(
                "| %-12s | %-9s | %5.0f %-7s | %11.1f MB | %6.0f %-12s | %6.0f %-12s |\n",
                $label,
                $impl,
                median($c['rss']),
                fmtCi(medianCi($c['rss'])),
                max(0.0, median($c['rss']) - $floor),
                median($c['metric']),
                fmtCi(medianCi($c['metric'])),
                median($c['extra']['lookup_kops']),
                fmtCi(medianCi($c['extra']['lookup_kops'])),
            );
        }
    }
}

// ── 3. Sliding-window eviction ───────────────────────────────────────────

if ($want('window')) {
    $sizes = [10_000, 100_000, 1_000_000];
    echo "\n\n## 3. Sliding-window eviction vs retained-set size\n\n";
    printf(
        "%s expired buckets are dropped in every cell; only the RETAINED set grows.\n",
        number_format(WINDOW_EXPIRED),
    );
    echo "A cost that grows with the retained column is a cost paid for data being kept.\n\n";
    printf("| %-9s | %-24s | %-24s | %-24s |\n", 'retained', 'judy deleteRange (µs)', 'array-scan (µs)', 'array_filter (µs)');
    echo "|-----------|--------------------------|--------------------------|--------------------------|\n";

    $curve = [];
    $cis   = [];
    foreach ($sizes as $n) {
        $cols = [];
        foreach (['judy', 'array-scan', 'array-filter'] as $impl) {
            $c = cell('window', $impl, $n, 0, $runs);
            if ($c === null) {
                $cols[] = 'FAILED';
                continue;
            }
            $m = median($c['metric']);
            $curve[number_format($n) . ' retained'][$impl] = $m;
            $cis[$n][$impl] = medianCi($c['metric']);
            $cols[] = sprintf('%s %s', number_format($m, $m >= 100 ? 0 : 2), fmtCi($cis[$n][$impl]));
        }
        printf("| %-9s | %-24s | %-24s | %-24s |\n", number_format($n), ...$cols);
    }
    echo "\n";
    foreach (asciiBars($curve, 'µs') as $line) {
        echo $line, "\n";
    }
    // Direction matters more than separation here: Judy's cost is flat, so the
    // two curves cross somewhere and the winner changes with retained size.
    echo "\n";
    foreach (['array-scan', 'array-filter'] as $rival) {
        foreach ($sizes as $n) {
            if (!isset($cis[$n]['judy'], $cis[$n][$rival])) {
                continue;
            }
            $j = $cis[$n]['judy'];
            $r = $cis[$n][$rival];
            printf(
                "- retained=%-9s judy vs %-13s %s\n",
                number_format($n),
                $rival . ':',
                !separated($j, $r)
                    ? 'no measured separation (CIs overlap)'
                    : ($j[1] < $r[0] ? 'judy wins' : $rival . ' wins'),
            );
        }
    }
}

// ── 4. Floor / CIDR lookup ───────────────────────────────────────────────

if ($want('floor')) {
    $sizes = [10_000, 100_000, 1_000_000];
    echo "\n\n## 4. Floor lookup: Judy last() vs sorted array + binary search\n\n";
    echo "The comparison Judy is least likely to win. Binary search over a packed\n";
    echo "sorted array is genuinely good at static range tables; the question is\n";
    echo "what happens when the table changes.\n\n";
    printf(
        "| %-9s | %-12s | %-21s | %-15s | %-21s |\n",
        'ranges', 'impl', 'lookup (ns/op)', 'build (ms)', 'insert (µs/range)',
    );
    echo "|-----------|--------------|-----------------------|-----------------|-----------------------|\n";

    $cis = [];
    foreach ($sizes as $n) {
        foreach (['judy', 'sorted-array'] as $impl) {
            $c = cell('floor', $impl, $n, 0, $runs);
            if ($c === null) {
                printf("| %-9s | %-12s | %-63s |\n", number_format($n), $impl, 'FAILED');
                continue;
            }
            $cis[$n][$impl] = ['lookup' => medianCi($c['metric']), 'update' => medianCi($c['extra']['update_us'])];
            printf(
                "| %-9s | %-12s | %5.0f %-15s | %6.1f %-8s | %7.2f %-13s |\n",
                number_format($n),
                $impl,
                median($c['metric']),
                fmtCi(medianCi($c['metric'])),
                median($c['extra']['build_ms']),
                fmtCi(medianCi($c['extra']['build_ms'])),
                median($c['extra']['update_us']),
                fmtCi(medianCi($c['extra']['update_us'])),
            );
        }
    }
    echo "\n";
    foreach ($sizes as $n) {
        if (!isset($cis[$n]['judy'], $cis[$n]['sorted-array'])) {
            continue;
        }
        foreach (['lookup', 'update'] as $metric) {
            $j = $cis[$n]['judy'][$metric];
            $s = $cis[$n]['sorted-array'][$metric];
            if (!separated($j, $s)) {
                printf("- n=%-9s %-6s no measured separation (CIs overlap)\n", number_format($n), $metric);
                continue;
            }
            printf(
                "- n=%-9s %-6s %s wins\n",
                number_format($n),
                $metric,
                $j[1] < $s[0] ? 'judy' : 'sorted-array',
            );
        }
    }
}

echo "\n\n---\n\n";
echo "Reminders for anyone quoting these numbers:\n";
echo "- APCu is shared across FPM workers; Judy is per process. A per-process\n";
echo "  latency win is not a reason to drop a shared cache.\n";
echo "- Redis/Memcached are excluded on purpose: their cost includes IPC or\n";
echo "  network round-trips against an in-process structure.\n";
echo "- Timings are only meaningful from an idle machine. Check load average\n";
echo "  before and between runs and disclose it alongside the numbers.\n";
