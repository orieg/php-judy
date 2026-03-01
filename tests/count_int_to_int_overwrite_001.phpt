--TEST--
Judy INT_TO_INT count() correctness with overwrites and deletes
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);

echo "Empty: " . $j->count() . "\n";

// Insert 3 entries
$j[0] = 100;
$j[1] = 200;
$j[2] = 300;
echo "After 3 inserts: " . $j->count() . "\n";

// Overwrite existing key
$j[1] = 999;
echo "After overwrite: " . $j->count() . "\n";

// Overwrite with 0 (valid value, not a delete)
$j[0] = 0;
echo "After overwrite with 0: " . $j->count() . "\n";

// Delete
unset($j[2]);
echo "After unset: " . $j->count() . "\n";

// Delete non-existent
unset($j[999]);
echo "After unset non-existent: " . $j->count() . "\n";

// Increment on new key (auto-creates)
$j->increment(50);
echo "After increment new key: " . $j->count() . "\n";

// Increment on existing key
$j->increment(50);
echo "After increment existing: " . $j->count() . "\n";

// Verify values
echo "j[0]=" . $j[0] . " j[1]=" . $j[1] . " j[50]=" . $j[50] . "\n";

echo "count() function: " . count($j) . "\n";
?>
--EXPECT--
Empty: 0
After 3 inserts: 3
After overwrite: 3
After overwrite with 0: 3
After unset: 2
After unset non-existent: 2
After increment new key: 3
After increment existing: 3
j[0]=0 j[1]=999 j[50]=2
count() function: 3
