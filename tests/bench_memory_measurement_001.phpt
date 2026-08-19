--TEST--
judy-bench: memory is measured as peak RSS, not the emalloc heap (issue #172)
--SKIPIF--
<?php
if (substr(PHP_OS, 0, 3) === 'WIN') die('skip POSIX shell required');
if (!function_exists('getrusage')) die('skip getrusage() not available');
if (!function_exists('shell_exec')) die('skip shell_exec() disabled');
$root = dirname(__DIR__);
if (!is_file("$root/examples/benchmarks/judy-bench.php")) die('skip judy-bench.php not present');
if (!is_file("$root/modules/judy.so")) die('skip requires in-tree build (modules/judy.so)');
?>
--FILE--
<?php
$root   = dirname(__DIR__);
$so     = "$root/modules/judy.so";
$script = "$root/examples/benchmarks/judy-bench.php";

/** Run judy-bench.php over core.int only, return [exit, decoded json]. */
function run_bench(string $extra, int $size = 200000): array {
    global $so, $script;
    $out = tempnam(sys_get_temp_dir(), 'jbm');
    $cmd = escapeshellarg(PHP_BINARY)
        . ' -n -d memory_limit=2G -d extension=' . escapeshellarg($so)
        . ' ' . escapeshellarg($script)
        . ' --group core.int --size ' . $size . ' --iterations 1'
        . ' ' . $extra
        . ' --json ' . escapeshellarg($out)
        . ' 2>/dev/null';
    exec($cmd . ' >/dev/null', $_, $status);
    $j = json_decode((string)@file_get_contents($out), true);
    @unlink($out);
    return [$status, is_array($j) ? $j : null];
}

// ── 1. The child entry point builds the structure it is asked for ───────────
// This is the instrument itself. It must load the extension under test and
// report a peak that is above an empty process, or the whole measurement is
// worthless.
$child = escapeshellarg(PHP_BINARY)
    . ' -n -d memory_limit=2G -d extension=' . escapeshellarg($so)
    . ' ' . escapeshellarg($script) . ' --mem-child ';
$floor = json_decode((string)shell_exec($child . 'floor php 0 2>/dev/null'), true);
$cell  = json_decode((string)shell_exec($child . 'core.int_to_int judy 200000 2>/dev/null'), true);

echo "== child ==\n";
echo "floor is json: ", is_array($floor) ? 'yes' : 'no', "\n";
echo "cell count: ", $cell['count'], "\n";
echo "cell built above floor: ", ($cell['peak_rss'] > $floor['peak_rss']) ? 'yes' : 'no', "\n";
// The defect in one line: PHP's own memory manager sees ~160 bytes of wrapper
// for 200,000 integer keys, because libJudy allocates through malloc(3).
echo "child heap_bytes under 1 KB: ", ($cell['heap_bytes'] < 1024) ? 'yes' : 'no', "\n";
$child_unknown = shell_exec($child . 'core.nope judy 10 2>&1');
echo "unknown workload rejected: ",
    (strpos((string)$child_unknown, 'unknown workload') !== false) ? 'yes' : 'no', "\n";

// ── 2. --memory rss (the default) records a footprint next to the heap ──────
[$status, $j] = run_bench('');
echo "\n== --memory rss (default) ==\n";
echo "exit=$status\n";
echo "method: ", $j['metadata']['memory_method'], "\n";
echo "resolution is int: ", is_int($j['metadata']['memory_resolution_bytes']) ? 'yes' : 'no', "\n";

$bm = $j['benchmarks'];
// heap_bytes must survive: bench-compare.php, bench-gate.php and
// bench-threearm.php all use its presence to tell a memory row from a timing
// row. Removing it would silently reclassify these rows as 0 ms timings.
echo "heap_bytes still present: ",
    (isset($bm['core.bitset.heap.judy']['heap_bytes'])
     && isset($bm['core.bitset.heap.php']['heap_bytes'])) ? 'yes' : 'no', "\n";
echo "rss_bytes added: ",
    (isset($bm['core.bitset.heap.judy']['rss_bytes'])
     && isset($bm['core.int_to_int.heap.judy']['rss_bytes'])) ? 'yes' : 'no', "\n";

// The regression this test exists to catch. INT_TO_INT at 200k is ~1.6 MB of
// trie; the emalloc heap reports the wrapper object only. If rss_bytes ever
// collapses back to heap_bytes, the measurement has reverted.
$h = $bm['core.int_to_int.heap.judy']['heap_bytes'];
$r = $bm['core.int_to_int.heap.judy']['rss_bytes'];
echo "int_to_int heap under 1 KB: ", ($h < 1024) ? 'yes' : 'no', "\n";
echo "int_to_int rss over 256 KB: ", ($r > 262144) ? 'yes' : 'no', "\n";

// And the headline consequence: measured against the PHP array, the ratio is a
// small multiple rather than the five-figure one the heap delta produced.
$pr = $bm['core.int_to_int.heap.php']['rss_bytes'];
$ph = $bm['core.int_to_int.heap.php']['heap_bytes'];
// The old instrument reported a five-figure multiple here; the new one a
// small one. Asserted as thresholds, not exact values: the PHP array's own
// footprint moves with the PHP version and the page size.
echo "int_to_int ratio by heap over 1000x: ",
    (($ph / max($h, 1)) > 1000.0) ? 'yes' : 'no', "\n";
echo "int_to_int ratio by rss under 10x: ",
    (($pr / max($r, 1)) < 10.0) ? 'yes' : 'no', "\n";

// ── 3. --memory off is the old behaviour, unchanged ────────────────────────
// The automated drivers pass this; they must keep getting heap_bytes and no
// child processes.
[$status, $j] = run_bench('--memory off');
echo "\n== --memory off ==\n";
echo "exit=$status\n";
echo "method: ", $j['metadata']['memory_method'], "\n";
echo "heap_bytes present: ",
    isset($j['benchmarks']['core.bitset.heap.judy']['heap_bytes']) ? 'yes' : 'no', "\n";
echo "rss_bytes absent: ",
    !isset($j['benchmarks']['core.bitset.heap.judy']['rss_bytes']) ? 'yes' : 'no', "\n";

// ── 4. A bad --memory value is rejected, not reinterpreted ─────────────────
[$status, $j] = run_bench('--memory heap');
echo "\n== --memory heap (invalid) ==\n";
echo "exit=$status\n";
echo "json written: ", $j === null ? 'no' : 'yes', "\n";
?>
--EXPECT--
== child ==
floor is json: yes
cell count: 200000
cell built above floor: yes
child heap_bytes under 1 KB: yes
unknown workload rejected: yes

== --memory rss (default) ==
exit=0
method: peak_rss_child_floor_subtracted
resolution is int: yes
heap_bytes still present: yes
rss_bytes added: yes
int_to_int heap under 1 KB: yes
int_to_int rss over 256 KB: yes
int_to_int ratio by heap over 1000x: yes
int_to_int ratio by rss under 10x: yes

== --memory off ==
exit=0
method: php_heap_only
heap_bytes present: yes
rss_bytes absent: yes

== --memory heap (invalid) ==
exit=1
json written: no
