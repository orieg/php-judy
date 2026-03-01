--TEST--
Judy INT_TO_PACKED count() correctness with overwrites and deletes
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);

echo "Empty: " . $j->count() . "\n";

$j[0] = "packed_string";
$j[1] = 42;
$j[2] = 3.14;
echo "After 3 inserts: " . $j->count() . "\n";

// Overwrite existing
$j[1] = "now_a_string";
echo "After overwrite: " . $j->count() . "\n";

// Delete
unset($j[2]);
echo "After unset: " . $j->count() . "\n";

echo "count() function: " . count($j) . "\n";
?>
--EXPECT--
Empty: 0
After 3 inserts: 3
After overwrite: 3
After unset: 2
count() function: 2
