--TEST--
Test foreach() with negative indices in INT_TO_INT Judy array
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/*
Ref. https://github.com/orieg/php-judy/issues/3

Issue #3 was an infinite foreach when keys 0 and -1 were both present: the
iterator called the Judy1 macros (J1F/J1N) against a JudyL array, so the walk
never terminated. It was fixed in judy_iterator.c by using JLF/JLN for the
JudyL-backed types; this test guards that fix.

The loop below is bounded and prints only after it finishes, on purpose. A
regression must fail this test, not hang it: run-tests.php's timeout is an
idle timeout on the child's output, so a runaway loop that printed on every
iteration would never go idle and would spin until the CI job cap instead of
failing.
*/
$judy = new Judy(Judy::INT_TO_INT);
echo "Set INT_TO_INT Judy object\n";
$judy[1]  = 457;
$judy[0]  = 456;
$judy[-1] = 123;
$judy[-2] = 122;

$seen = [];
$n = 0;
foreach ($judy as $k => $v) {
    if (++$n > 100) { $seen[] = 'RUNAWAY'; break; }
    $seen[] = "k: $k, v: $v";
}
echo implode("\n", $seen), "\n";

// Keys are unsigned words, so the negative ones sort after the non-negative
// ones and each keeps its own index.
echo "count: ", $judy->count(), "\n";

unset($judy);
?>
--EXPECT--
Set INT_TO_INT Judy object
k: 0, v: 456
k: 1, v: 457
k: -2, v: 122
k: -1, v: 123
count: 4
