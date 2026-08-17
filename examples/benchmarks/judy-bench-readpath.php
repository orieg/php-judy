<?php
/**
 * Read-path microbenchmark: ordered traversal of the string-keyed types.
 *
 * The twin of judy-bench-writepath.php, and the other half of the issue #85
 * gate. That issue's one large addressable finding was that the four
 * `*_HASH` / `*_ADAPTIVE` types walked their JudySL `key_index` for keys and
 * then performed a *second* full lookup per element to fetch the value — 22
 * ns/elem at 16-byte keys, 98 ns/elem at 40-byte keys, up to 46% of
 * `forEach()`. Nothing in judy-bench.php measures ordered iteration on those
 * four types, which is why the issue had to hand-roll its decomposition; this
 * script closes that gap so the same measurement can be re-run.
 *
 * `keys()` is the control throughout. It walks the identical trie and builds
 * the identical zvals but never touches a value, so `values() - keys()` is the
 * value fetch on its own, isolated from the walk.
 *
 * Key length is the discriminator that matters here and is a flag: the second
 * lookup was a JudyHS `JHSG`, whose cost grows with the key because it digests
 * the whole key, while the walk itself is nearly flat in key length. Run at
 * least 16 and 40.
 *
 * Usage — one build per process:
 *
 *   PHP_INI_SCAN_DIR= php -d extension=/path/to/modules/judy.so \
 *       examples/benchmarks/judy-bench-readpath.php --size 300000 --keylen 40
 *
 * `PHP_INI_SCAN_DIR=` is not optional. If any conf.d ini already loads judy.so
 * — a Docker image built with `docker-php-ext-enable judy`, a PIE or system
 * install — that copy wins, `-d extension=` is ignored with only a
 * "Module judy is already loaded" warning, and the run silently measures the
 * wrong build. `judy_version()` will not reveal it; both report the same
 * version. See BENCHMARK.md.
 *
 * Comparing two builds: drive it through scripts/bench-compare.php, which does
 * the ABBA interleaving, per-round pairing and bootstrap CIs. Two aggregate
 * numbers taken minutes apart is the shape that produced the false regressions
 * in issue #87.
 *
 *   php scripts/bench-compare.php \
 *       --bench examples/benchmarks/judy-bench-readpath.php \
 *       --groups read --baseline-so <a>/judy.so --current-so <b>/judy.so
 *
 * `--group` is accepted for that contract and ignored: every case here belongs
 * to the one read-path group. The driver forwards only --size/--iterations, so
 * key length travels in the environment instead — export JUDY_BENCH_KEYLEN and
 * run the comparison once per length:
 *
 *   JUDY_BENCH_KEYLEN=40 php scripts/bench-compare.php --bench ... --groups read
 *
 * An explicit --keylen still wins over the environment.
 *
 * Timings are only meaningful from an idle machine: check load average before
 * and between runs and treat anything above cores/2 as contaminated.
 */

$opts = getopt('', ['size:', 'iterations:', 'keylen:', 'json:', 'group:']);
$size = (int)($opts['size'] ?? 300000);
$iters = (int)($opts['iterations'] ?? 5);
$keylen = (int)($opts['keylen'] ?? getenv('JUDY_BENCH_KEYLEN') ?: 16);
$json_out = $opts['json'] ?? null;

if (!extension_loaded('judy')) {
    fwrite(STDERR, "judy not loaded\n");
    exit(1);
}

/**
 * optimizeIteration arm switch.
 *
 * The mirror is opt-in per instance, so comparing "mirror on" against a
 * baseline build means constructing differently in the two arms, not just
 * linking a different judy.so. Export JUDY_BENCH_OPTIMIZE_ITERATION=1 and every
 * array below is built with it on — but only on a build that has the argument,
 * so the same command line can drive an older baseline .so, which silently
 * constructs the way it always did. That is exactly the A/B the trade needs:
 * today's behaviour against opted-in behaviour.
 *
 * With the variable unset both arms construct plainly, which is the comparison
 * that has to come out flat.
 */
$optimizeIteration = (bool)getenv('JUDY_BENCH_OPTIMIZE_ITERATION');
$supportsOptimizeIteration = method_exists('Judy', 'isIterationOptimized');
function judy_new(int $type): Judy
{
    global $optimizeIteration, $supportsOptimizeIteration;
    return ($optimizeIteration && $supportsOptimizeIteration)
        ? new Judy($type, true)
        : new Judy($type);
}

// Same key shape as the issue's decomposition and as the C harness in
// research/: a shared "user:" prefix every 10 keys, padded to $keylen.
$keys = [];
for ($i = 0; $i < $size; $i++) {
    $k = sprintf('user:%08d:f%d', intdiv($i, 10), $i % 10);
    $keys[] = strlen($k) >= $keylen ? substr($k, 0, $keylen) : str_pad($k, $keylen, 'x');
}

$results = [];

/** Run $body $iters times over a pre-filled array. Reports ns/elem and ms/run. */
function bench(string $name, Judy $j, int $ops, callable $body, int $iters): array {
    $runs_ns = [];
    $runs_ms = [];
    for ($r = 0; $r < $iters; $r++) {
        $t0 = hrtime(true);
        $body($j);
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

$types = [
    'str_int'            => Judy::STRING_TO_INT,
    'str_mixed'          => Judy::STRING_TO_MIXED,
    'str_int_hash'       => Judy::STRING_TO_INT_HASH,
    'str_mixed_hash'     => Judy::STRING_TO_MIXED_HASH,
    'str_int_adaptive'   => Judy::STRING_TO_INT_ADAPTIVE,
    'str_mixed_adaptive' => Judy::STRING_TO_MIXED_ADAPTIVE,
];

foreach ($types as $tname => $type) {
    $j = judy_new($type);
    for ($i = 0; $i < $size; $i++) {
        $j[$keys[$i]] = $i;
    }

    // Control: the walk with no value fetch at all.
    $results["$tname.keys"] = bench("$tname.keys", $j, $size,
        function ($j) { $j->keys(); }, $iters);

    // The walk plus one value per element. values() - keys() is the fetch.
    $results["$tname.values"] = bench("$tname.values", $j, $size,
        function ($j) { $j->values(); }, $iters);

    $results["$tname.toArray"] = bench("$tname.toArray", $j, $size,
        function ($j) { $j->toArray(); }, $iters);

    // The foreach opcode drives judy_iterator_move_forward().
    $results["$tname.foreach"] = bench("$tname.foreach", $j, $size,
        function ($j) { $s = 0; foreach ($j as $k => $v) { $s++; } }, $iters);

    // forEach() drives judy_callback_iterator(), a separate walk.
    $results["$tname.forEach"] = bench("$tname.forEach", $j, $size,
        function ($j) { $j->forEach(function ($v, $k) {}); }, $iters);

    // filter(false) keeps the walk but builds nothing; map() writes a result.
    $results["$tname.filter_none"] = bench("$tname.filter_none", $j, $size,
        function ($j) { $j->filter(fn($v, $k) => false); }, $iters);
    $results["$tname.map"] = bench("$tname.map", $j, $size,
        function ($j) { $j->map(fn($v, $k) => $v); }, $iters);

    // Control: point lookup, which the mirror deliberately does not change.
    $results["$tname.get"] = bench("$tname.get", $j, $size,
        function ($j) use ($keys, $size) {
            $s = 0;
            for ($i = 0; $i < $size; $i++) { $s += (int)$j[$keys[$i]]; }
        }, $iters);

    unset($j);
}

$benchmarks = [];
foreach ($results as $name => $entry) {
    // bench-compare.php selects on the `.judy` suffix; `.php` is its slot for
    // PHP-array control work, which this script has none of.
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
        // Recorded per arm, so a JSON pair makes it obvious which side
        // actually had the mirror on rather than leaving it to the shell
        // history.
        'optimize_iteration' => $optimizeIteration && $supportsOptimizeIteration,
        'groups'       => ['read'],
    ],
    'benchmarks' => $benchmarks,
    'results' => $results,
];

if ($json_out !== null) {
    file_put_contents($json_out, json_encode($document, JSON_PRETTY_PRINT) . "\n");
} else {
    echo json_encode($document, JSON_PRETTY_PRINT), "\n";
}
