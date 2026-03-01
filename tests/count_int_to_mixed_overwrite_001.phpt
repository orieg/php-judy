--TEST--
Judy INT_TO_MIXED count() correctness with overwrites and deletes
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_MIXED);

echo "Empty: " . $j->count() . "\n";

$j[0] = "hello";
$j[1] = 42;
$j[2] = [1, 2, 3];
echo "After 3 inserts: " . $j->count() . "\n";

// Overwrite existing
$j[1] = "replaced";
echo "After overwrite: " . $j->count() . "\n";

// Delete
unset($j[2]);
echo "After unset: " . $j->count() . "\n";

// Add via append ($j[] = val)
$j[] = "appended";
echo "After append: " . $j->count() . "\n";

echo "count() function: " . count($j) . "\n";
?>
--EXPECT--
Empty: 0
After 3 inserts: 3
After overwrite: 3
After unset: 2
After append: 3
count() function: 3
