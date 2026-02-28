--TEST--
Judy clone INT_TO_INT independence
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 100;
$j[5] = 200;
$j[10] = 300;

$clone = clone $j;

// Verify clone has same data
echo "Clone count: " . $clone->count() . "\n";
echo "Clone[0]: " . $clone[0] . "\n";
echo "Clone[5]: " . $clone[5] . "\n";
echo "Clone[10]: " . $clone[10] . "\n";

// Modify original, verify clone unchanged
$j[0] = 999;
$j[20] = 400;
unset($j[5]);

echo "After modify - original count: " . $j->count() . "\n";
echo "After modify - clone count: " . $clone->count() . "\n";
echo "Original[0]: " . $j[0] . "\n";
echo "Clone[0]: " . $clone[0] . "\n";
echo "Original[5] exists: " . (isset($j[5]) ? "yes" : "no") . "\n";
echo "Clone[5]: " . $clone[5] . "\n";
echo "Original[20]: " . $j[20] . "\n";
echo "Clone[20] exists: " . (isset($clone[20]) ? "yes" : "no") . "\n";

// Modify clone, verify original unchanged
$clone[10] = 888;
echo "Original[10]: " . $j[10] . "\n";
echo "Clone[10]: " . $clone[10] . "\n";
?>
--EXPECT--
Clone count: 3
Clone[0]: 100
Clone[5]: 200
Clone[10]: 300
After modify - original count: 3
After modify - clone count: 3
Original[0]: 999
Clone[0]: 100
Original[5] exists: no
Clone[5]: 200
Original[20]: 400
Clone[20] exists: no
Original[10]: 300
Clone[10]: 888
