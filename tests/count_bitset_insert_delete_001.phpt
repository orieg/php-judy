--TEST--
Judy BITSET count() correctness during insert/re-insert/delete cycles
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::BITSET);

// Empty
echo "Empty: " . $j->count() . "\n";

// Insert 5 bits
$j[0] = true;
$j[10] = true;
$j[20] = true;
$j[30] = true;
$j[40] = true;
echo "After 5 inserts: " . $j->count() . "\n";

// Re-insert same bit (should not increment)
$j[10] = true;
$j[20] = true;
echo "After 2 re-inserts: " . $j->count() . "\n";

// Unset via false
$j[10] = false;
echo "After unset via false: " . $j->count() . "\n";

// Unset via unset()
unset($j[20]);
echo "After unset(): " . $j->count() . "\n";

// Unset non-existent
unset($j[999]);
echo "After unset non-existent: " . $j->count() . "\n";

// Unset already-unset
$j[10] = false;
echo "After re-unset: " . $j->count() . "\n";

// count() via count() function
echo "count() function: " . count($j) . "\n";

// Free and check
$j->free();
echo "After free: " . $j->count() . "\n";
?>
--EXPECT--
Empty: 0
After 5 inserts: 5
After 2 re-inserts: 5
After unset via false: 4
After unset(): 3
After unset non-existent: 3
After re-unset: 3
count() function: 3
After free: 0
