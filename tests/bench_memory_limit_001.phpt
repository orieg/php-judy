--TEST--
judy-bench: memory_limit is a floor a caller can raise, not a cap that overrides them
--SKIPIF--
<?php
if (substr(PHP_OS, 0, 3) === 'WIN') die('skip POSIX shell required');
$root = dirname(__DIR__);
if (!is_file("$root/examples/benchmarks/judy-bench.php")) die('skip judy-bench.php not present');
if (!is_file("$root/modules/judy.so")) die('skip requires in-tree build (modules/judy.so)');
?>
--FILE--
<?php
/*
 * The suite used to set a flat `ini_set('memory_limit', '2G')` before parsing
 * argv, which silently overrode the `-d memory_limit=-1` that both bench
 * drivers pass their children and made the large string groups impossible to
 * run at all. What is pinned here is the precedence, not the number: a caller
 * asking for MORE must keep what they asked for, a caller asking for LESS is
 * still raised to the floor, and --memory-limit beats both.
 */
$root   = dirname(__DIR__);
$so     = "$root/modules/judy.so";
$script = "$root/examples/benchmarks/judy-bench.php";

function run_bench(string $label, string $ini, string $extra = '', string $ini_extra = ''): void {
    global $so, $script;

    // A tiny core.int run: enough to reach the banner, cheap enough for CI.
    $cmd = escapeshellarg(PHP_BINARY)
        . ' -d extension=' . escapeshellarg($so)
        . ' -d memory_limit=' . escapeshellarg($ini)
        . ' ' . $ini_extra
        . ' ' . escapeshellarg($script)
        . ' --group core.int --size 200 --iterations 1 ' . $extra
        . ' 2>&1';
    exec($cmd, $output, $status);

    $seen = null;
    foreach ($output as $line) {
        if (preg_match('/^\s*memory_limit:\s*(\S+)/', $line, $m)) {
            $seen = $m[1];
        } elseif (preg_match('/^(Invalid --memory-limit:.*)$/', $line, $m)) {
            $seen = $m[1];
        }
    }
    if ($seen === null) {
        // Only reachable when the child never got as far as reporting a limit.
        // Quote what it said instead of a bare "none": a fatal in the child
        // would otherwise collapse every row to the same uninformative token
        // and hide which dependency or path actually broke.
        $err = 'no limit reported';
        foreach ($output as $line) {
            if (preg_match('/(Fatal error|Parse error|Warning|Error):.*/', $line, $m)) {
                $err = $m[0];
                break;
            }
        }
        $seen = "NONE — $err";
    }
    echo "$label: exit=$status effective=$seen\n";
}

// A caller below the floor is raised to it — the historical default.
run_bench('below floor      ', '128M');
// Equal to the floor: unchanged either way.
run_bench('at floor         ', '2G');
// Above the floor stays put, rather than being silently lowered to 2G.
run_bench('above floor      ', '4G');
// The form every bench driver actually passes. This is the regression.
run_bench('unlimited (-d -1)', '-1');
// An explicit request wins outright, including downward against -1.
run_bench('explicit wins    ', '-1', '--memory-limit 256M');
// ...and is rejected rather than silently reinterpreted when unparseable.
run_bench('explicit invalid ', '-1', '--memory-limit 4gigs');
// A magnitude that cannot fit an int is rejected, not overflowed to float:
// letting it through cost an uncaught TypeError on the parser's return type.
run_bench('explicit overflow', '-1', '--memory-limit 99999999999999999G');

// The suite must parse its own arguments using core + pcre only. The debug CI
// job builds its PHP `--disable-all`, where ext/filter is absent; a
// filter_var() call in the size parser made every row above fatal there while
// passing everywhere else. disable_functions reproduces that here, and is a
// no-op on a build where the function was never compiled in.
run_bench('no ext/filter    ', '128M', '', '-d disable_functions=filter_var,ctype_digit');
?>
--EXPECT--
below floor      : exit=0 effective=2G
at floor         : exit=0 effective=2G
above floor      : exit=0 effective=4G
unlimited (-d -1): exit=0 effective=-1
explicit wins    : exit=0 effective=256M
explicit invalid : exit=1 effective=Invalid --memory-limit: 4gigs. Use a PHP ini size such as 4G, 512M, 1073741824, or -1 for unlimited.
explicit overflow: exit=1 effective=Invalid --memory-limit: 99999999999999999G. Use a PHP ini size such as 4G, 512M, 1073741824, or -1 for unlimited.
no ext/filter    : exit=0 effective=2G
