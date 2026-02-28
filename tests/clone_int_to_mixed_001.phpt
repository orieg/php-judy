--TEST--
Judy clone INT_TO_MIXED with complex values
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_MIXED);
$j[0] = "hello";
$j[1] = [1, 2, 3];
$j[2] = 42;

$clone = clone $j;

// Verify clone has same data
echo "Clone count: " . $clone->count() . "\n";
echo "Clone[0]: " . $clone[0] . "\n";
echo "Clone[1]: " . implode(",", $clone[1]) . "\n";
echo "Clone[2]: " . $clone[2] . "\n";

// Modify original, verify clone unchanged
$j[0] = "world";
$j[1] = "replaced";
unset($j[2]);

echo "After modify - original count: " . $j->count() . "\n";
echo "After modify - clone count: " . $clone->count() . "\n";
echo "Original[0]: " . $j[0] . "\n";
echo "Clone[0]: " . $clone[0] . "\n";
echo "Clone[1]: " . implode(",", $clone[1]) . "\n";
echo "Clone[2]: " . $clone[2] . "\n";
?>
--EXPECT--
Clone count: 3
Clone[0]: hello
Clone[1]: 1,2,3
Clone[2]: 42
After modify - original count: 2
After modify - clone count: 3
Original[0]: world
Clone[0]: hello
Clone[1]: 1,2,3
Clone[2]: 42
