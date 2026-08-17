<?php
/**
 * Write-path microbenchmark: string-keyed insert, overwrite and increment.
 *
 * The suites in judy-bench.php cover reads, iteration and bulk operations. This
 * one isolates the *write* path on string-keyed types — `offsetSet` insert and
 * overwrite across all six, `increment()` on the three that support it, and the
 * ADAPTIVE short-string (SSO) branch, which has the shortest write path and is
 * therefore the most sensitive to added indirection. Point lookup is included
 * as a control: it should not move.
 *
 * Why it exists: any change that touches key registration is invisible to an
 * iteration-only benchmark. It was written for the issue #85 B1 refactor
 * (routing every string-keyed value write through one slot-acquisition path)
 * and is kept because the remaining #85 work adds a `key_index` lookup to the
 * overwrite path, which needs exactly this gate. It caught a real +3.0%
 * regression on ADAPTIVE SSO overwrite that a code review had passed.
 *
 * Usage — one build per process, emitting JSON:
 *
 *   PHP_INI_SCAN_DIR= php -d extension=/path/to/modules/judy.so \
 *       examples/benchmarks/judy-bench-writepath.php --size 200000 > arm.json
 *
 * `PHP_INI_SCAN_DIR=` is not optional. If any conf.d ini already loads judy.so
 * — a Docker image built with `docker-php-ext-enable judy`, a PIE or system
 * install — that copy wins, `-d extension=` is ignored with only a
 * "Module judy is already loaded" warning, and the run silently measures the
 * wrong build. `judy_version()` will not reveal it; both report the same
 * version. See BENCHMARK.md.
 *
 * Comparing two builds: run the arms **interleaved**, alternating order round
 * by round (ABBA), and compare per-round ratios rather than two aggregate
 * numbers. Sequential arms minutes apart is the exact shape that produced the
 * false regressions in issue #87. `scripts/bench-compare.php` implements that
 * treatment — including the bootstrap CIs and the instability guard — so this
 * script speaks that driver's contract (`--group`, `--json`, a `benchmarks`
 * map of `median_ms`) and is driven through it rather than by hand:
 *
 *   php scripts/bench-compare.php \
 *       --bench examples/benchmarks/judy-bench-writepath.php \
 *       --groups write --baseline-so <a>/judy.so --current-so <b>/judy.so
 *
 * `--group` is accepted for that contract and ignored: every case here belongs
 * to the one write-path group.
 *
 * Timings are only meaningful from an idle machine: check load average before
 * and between runs and treat anything above cores/2 as contaminated.
 */

$opts = getopt('', ['size:', 'iterations:', 'keylen:', 'json:', 'group:']);
$size = (int)($opts['size'] ?? 200000);
$iters = (int)($opts['iterations'] ?? 5);
$keylen = (int)($opts['keylen'] ?? 16);
$json_out = $opts['json'] ?? null;

if (!extension_loaded('judy')) {
    fwrite(STDERR, "judy not loaded\n");
    exit(1);
}

// Keys shaped like the issue's: a shared prefix every 10 keys, padded to
// $keylen. Precomputed so key construction never lands inside a timed region.
$keys = [];
for ($i = 0; $i < $size; $i++) {
    $k = sprintf('user:%08d:f%d', intdiv($i, 10), $i % 10);
    $keys[] = strlen($k) >= $keylen ? substr($k, 0, $keylen) : str_pad($k, $keylen, 'x');
}
// Short keys for the ADAPTIVE SSO path (< 8 bytes).
$short = [];
for ($i = 0; $i < $size; $i++) {
    $short[] = base_convert((string)$i, 10, 36);
}

/**
 * Run $body $iters times. $setup runs untimed before each.
 *
 * Reports both shapes: ns/op for reading by eye, and the wall-clock ms per run
 * that scripts/bench-compare.php pairs and bootstraps.
 */
function bench(string $name, int $ops, callable $setup, callable $body, int $iters): array {
    $runs_ns = [];
    $runs_ms = [];
    for ($r = 0; $r < $iters; $r++) {
        $state = $setup();
        $t0 = hrtime(true);
        $body($state);
        $ns = hrtime(true) - $t0;
        $runs_ns[] = $ns / $ops;
        $runs_ms[] = $ns / 1e6;
    }
    sort($runs_ns);
    sort($runs_ms);
    return [
        'ns' => $runs_ns[intdiv(count($runs_ns), 2)],
        'min' => $runs_ns[0],
        'max' => $runs_ns[count($runs_ns) - 1],
        'median_ms' => round($runs_ms[intdiv(count($runs_ms), 2)], 4),
        'runs_ms' => array_map(fn($v) => round($v, 4), $runs_ms),
    ];
}

$results = [];
function record(string $name, array $r): void {
    global $results;
    $results[$name] = $r;
}

$types = [
    'str_int'            => Judy::STRING_TO_INT,
    'str_mixed'          => Judy::STRING_TO_MIXED,
    'str_int_hash'       => Judy::STRING_TO_INT_HASH,
    'str_mixed_hash'     => Judy::STRING_TO_MIXED_HASH,
    'str_int_adaptive'   => Judy::STRING_TO_INT_ADAPTIVE,
    'str_mixed_adaptive' => Judy::STRING_TO_MIXED_ADAPTIVE,
];

foreach ($types as $tname => $type) {
    $mixed = str_contains($tname, 'mixed');

    // insert: a fresh array each iteration, filled once
    record("$tname.insert", bench("$tname.insert", $size,
        fn() => new Judy($type),
        function ($j) use ($keys, $size, $mixed) {
            for ($i = 0; $i < $size; $i++) { $j[$keys[$i]] = $mixed ? $i : $i; }
        }, $iters));

    // overwrite: the array is already full before the timer starts
    record("$tname.overwrite", bench("$tname.overwrite", $size,
        function () use ($type, $keys, $size, $mixed) {
            $j = new Judy($type);
            for ($i = 0; $i < $size; $i++) { $j[$keys[$i]] = $i; }
            return $j;
        },
        function ($j) use ($keys, $size) {
            for ($i = 0; $i < $size; $i++) { $j[$keys[$i]] = $i + 1; }
        }, $iters));

    // control: point lookup, an untouched path
    record("$tname.get", bench("$tname.get", $size,
        function () use ($type, $keys, $size) {
            $j = new Judy($type);
            for ($i = 0; $i < $size; $i++) { $j[$keys[$i]] = $i; }
            return $j;
        },
        function ($j) use ($keys, $size) {
            $s = 0;
            for ($i = 0; $i < $size; $i++) { $s += (int)$j[$keys[$i]]; }
        }, $iters));
}

// ADAPTIVE short keys exercise the SSO (JudyL) half of the acquire helper.
foreach (['str_int_adaptive' => Judy::STRING_TO_INT_ADAPTIVE,
          'str_mixed_adaptive' => Judy::STRING_TO_MIXED_ADAPTIVE] as $tname => $type) {
    record("$tname.insert_sso", bench("$tname.insert_sso", $size,
        fn() => new Judy($type),
        function ($j) use ($short, $size) {
            for ($i = 0; $i < $size; $i++) { $j[$short[$i]] = $i; }
        }, $iters));
    record("$tname.overwrite_sso", bench("$tname.overwrite_sso", $size,
        function () use ($type, $short, $size) {
            $j = new Judy($type);
            for ($i = 0; $i < $size; $i++) { $j[$short[$i]] = $i; }
            return $j;
        },
        function ($j) use ($short, $size) {
            for ($i = 0; $i < $size; $i++) { $j[$short[$i]] = $i + 1; }
        }, $iters));
}

// increment(): the method B1 rerouted. `_new` creates every key, `_hot` is the
// existing-key path that used to write the value word on its own.
foreach (['str_int' => Judy::STRING_TO_INT, 'str_int_hash' => Judy::STRING_TO_INT_HASH] as $tname => $type) {
    record("$tname.increment_new", bench("$tname.increment_new", $size,
        fn() => new Judy($type),
        function ($j) use ($keys, $size) {
            for ($i = 0; $i < $size; $i++) { $j->increment($keys[$i]); }
        }, $iters));
    record("$tname.increment_hot", bench("$tname.increment_hot", $size,
        function () use ($type, $keys, $size) {
            $j = new Judy($type);
            for ($i = 0; $i < $size; $i++) { $j->increment($keys[$i]); }
            return $j;
        },
        function ($j) use ($keys, $size) {
            for ($i = 0; $i < $size; $i++) { $j->increment($keys[$i]); }
        }, $iters));
}

// control: increment() on INT_TO_INT, which B1 did not touch at all
record('int_int.increment_hot', bench('int_int.increment_hot', $size,
    function () use ($size) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) { $j->increment($i); }
        return $j;
    },
    function ($j) use ($size) {
        for ($i = 0; $i < $size; $i++) { $j->increment($i); }
    }, $iters));

$benchmarks = [];
foreach ($results as $name => $entry) {
    $benchmarks["$name.judy"] = $entry;
}

$document = [
    'metadata' => [
        'php_version'  => phpversion(),
        'judy_version' => judy_version(),
        'platform'     => PHP_OS . ' ' . php_uname('m'),
        'date'         => date('Y-m-d\TH:i:sP'),
        'size'         => $size,
        'keylen'       => $keylen,
        'iterations'   => $iters,
        'groups'       => ['write'],
    ],
    // The map bench-compare.php reads. It selects on a `.judy` suffix (the
    // other half of its convention, `.php`, is for PHP-array control work,
    // which this script has none of). Each entry carries median_ms/runs_ms for
    // the driver and ns/min/max for a human reading the file directly.
    'benchmarks' => $benchmarks,
    // Retained so `judy_version` and `results` keep working for anything that
    // read the pre-driver shape.
    'version' => judy_version(),
    'size' => $size,
    'keylen' => $keylen,
    'iterations' => $iters,
    'results' => $results,
];

if ($json_out !== null) {
    file_put_contents($json_out, json_encode($document, JSON_PRETTY_PRINT) . "\n");
} else {
    echo json_encode($document, JSON_PRETTY_PRINT), "\n";
}
