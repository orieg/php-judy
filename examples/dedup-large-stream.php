<?php
/**
 * Dedup a large stream of string keys with a Judy "seen set".
 *
 * The classic crawler / log-pipeline problem: millions of URLs or IDs,
 * you only need membership ("have I seen this?"), and a PHP array's
 * per-entry overhead blows the memory budget.
 *
 * Three implementations, honest trade-offs:
 *   array   - native PHP array set (baseline)
 *   judy    - STRING_TO_INT_HASH with exact keys: modest savings, zero risk
 *   hashed  - BITSET keyed by a 60-bit xxh3 fingerprint: the big win
 *             (~n^2/2^61 collision odds; fine for dedup, not for billing)
 *
 * Judy allocates outside PHP's memory manager, so memory_get_usage()
 * cannot see it. To compare fairly, this script re-executes itself and
 * measures each implementation's peak RSS in a separate process.
 *
 * Run: php examples/dedup-large-stream.php [n]
 */

if (!extension_loaded('judy')) {
    fwrite(STDERR, "The judy extension is not loaded.\n");
    exit(1);
}

$n    = (int)($argv[1] ?? 500_000);
$mode = $argv[2] ?? null;

// Simulated input stream with ~30% duplicates. In real code this would be
// fgets() over a log file or a message-queue consumer.
$stream = static function () use ($n): Generator {
    mt_srand(42);
    for ($i = 0; $i < $n; $i++) {
        yield 'https://example.com/page/' . mt_rand(0, (int)($n * 0.7));
    }
};

if ($mode !== null) {
    // Child: run one implementation and report unique count + peak RSS.
    $unique = 0;
    switch ($mode) {
        case 'judy':
            $seen = new Judy(Judy::STRING_TO_INT_HASH); // unsorted, fastest membership
            foreach ($stream() as $url) {
                if (!isset($seen[$url])) {
                    $seen[$url] = 1;
                    $unique++;
                    // ... process the first occurrence here ...
                }
            }
            break;
        case 'hashed':
            $seen = new Judy(Judy::BITSET);
            foreach ($stream() as $url) {
                $key = intval(substr(hash('xxh3', $url), 0, 15), 16); // 60-bit key
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $unique++;
                }
            }
            break;
        default:
            $seen = [];
            foreach ($stream() as $url) {
                if (!isset($seen[$url])) {
                    $seen[$url] = 1;
                    $unique++;
                }
            }
    }
    // ru_maxrss is bytes on macOS, kilobytes on Linux.
    $peak = getrusage()['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024);
    echo $unique, ' ', $peak, "\n";
    exit(0);
}

// Parent: run each implementation in its own process for a clean peak-RSS read.
// If the extension is not enabled in php.ini, point JUDY_SO at the .so file.
$self    = escapeshellarg(__FILE__);
$extFlag = getenv('JUDY_SO') ? '-d extension=' . escapeshellarg(getenv('JUDY_SO')) : '';

foreach (['array', 'judy', 'hashed'] as $impl) {
    $out = shell_exec(PHP_BINARY . " $extFlag $self $n $impl") ?? '';
    if (!preg_match('/^(\d+) (\d+)$/', trim($out), $m)) {
        fwrite(STDERR, "child run failed for '$impl' (is the judy extension enabled?)\n");
        continue;
    }
    printf("%-7s unique: %d   peak RSS: %.1f MB\n", $impl, $m[1], $m[2] / 1048576);
}
echo "(peak RSS includes the PHP runtime itself; the difference between rows\n";
echo " is what the seen-set costs. See BENCHMARK.md for full numbers.)\n";
