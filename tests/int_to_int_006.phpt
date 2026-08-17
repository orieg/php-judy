--TEST--
Check for Judy INT_TO_INT with unsigned and signed INT
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/*
An integer key is the full unsigned machine word. A negative PHP int is
reinterpreted as its unsigned bit pattern, so -1 addresses the maximum index
and reads back as -1. Keys therefore sort in unsigned order: the negative ones
come after every non-negative one.

Before 2.5.0 a negative offset was discarded and the value appended at the end
of the array instead, so $judy[-1] = v silently created some other key and
isset($judy[-1]) was false right after the write.
*/
$judy = new Judy(Judy::INT_TO_INT);

$judy[-2] = 9;
$judy[0] = 0;
$judy[1] = 17;

$judy[-1] = -17;
if ($judy[-1] === -17 && isset($judy[-1]))
    echo "\$judy[-1] stored the key -1 and reads back\n";
else
    echo "\$judy[-1] should be equal to -17 but got {$judy[-1]}\n";

// The append path is unaffected: it is still spelled $judy[] and still
// follows the highest key, which is now a negative one.
$judy[2] = -12;
if ($judy[2] == -12)
    echo "\$judy[2] has been reset to -12\n";
else
    echo "\$judy[2] should be equal to -12 but got {$judy[2]}\n";

$judy[3] = 8;
$judy[4] = -19;
$judy[-5] = 6;
$judy[-6] = 7;

print "Loop on Judy array with uint/int\n";
foreach($judy as $k=>$v)
    print "k: $k, v: $v\n";

// Distinct negative keys stay distinct rather than collapsing onto one index.
print "count: " . $judy->count() . "\n";

// PHP_INT_MIN is an ordinary key, not a synonym for 0.
$judy[PHP_INT_MIN] = 42;
print "PHP_INT_MIN: " . $judy[PHP_INT_MIN] . ", key 0 still " . $judy[0] . "\n";
echo "Done\n";
?>
--EXPECT--
$judy[-1] stored the key -1 and reads back
$judy[2] has been reset to -12
Loop on Judy array with uint/int
k: 0, v: 0
k: 1, v: 17
k: 2, v: -12
k: 3, v: 8
k: 4, v: -19
k: -6, v: 7
k: -5, v: 6
k: -2, v: 9
k: -1, v: -17
count: 9
PHP_INT_MIN: 42, key 0 still 0
Done
