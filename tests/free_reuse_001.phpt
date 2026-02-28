--TEST--
Judy free() then re-populate and verify array works normally
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// INT_TO_INT: free then reuse
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 100; $j[1] = 200;
echo "Before free: count=" . $j->count() . "\n";
$j->free();
echo "After free: count=" . $j->count() . "\n";

// Re-populate
$j[10] = 1000; $j[20] = 2000; $j[30] = 3000;
echo "After re-populate: count=" . $j->count() . "\n";
echo "Value at 10: " . $j[10] . "\n";
echo "Value at 20: " . $j[20] . "\n";
echo "Value at 30: " . $j[30] . "\n";
echo "Key 0 exists: " . (isset($j[0]) ? "yes" : "no") . "\n";

// STRING_TO_INT: free then reuse
$j = new Judy(Judy::STRING_TO_INT);
$j["x"] = 1;
$j->free();
$j["y"] = 2; $j["z"] = 3;
echo "STRING_TO_INT after reuse: count=" . $j->count() . "\n";
echo "y=" . $j["y"] . ", z=" . $j["z"] . "\n";
echo "x exists: " . (isset($j["x"]) ? "yes" : "no") . "\n";

// BITSET: free then reuse
$j = new Judy(Judy::BITSET);
$j[5] = true;
$j->free();
$j[100] = true; $j[200] = true;
echo "BITSET after reuse: count=" . $j->count() . "\n";
echo "100 set: " . (isset($j[100]) ? "yes" : "no") . "\n";
echo "5 set: " . (isset($j[5]) ? "yes" : "no") . "\n";
?>
--EXPECT--
Before free: count=2
After free: count=0
After re-populate: count=3
Value at 10: 1000
Value at 20: 2000
Value at 30: 3000
Key 0 exists: no
STRING_TO_INT after reuse: count=2
y=2, z=3
x exists: no
BITSET after reuse: count=2
100 set: yes
5 set: no
