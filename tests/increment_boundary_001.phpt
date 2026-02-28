--TEST--
Judy increment boundary: large amounts and edge cases
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);

// Increment by 0 (no-op on value, but creates key)
$result = $j->increment(0, 0);
echo "increment(0, 0): $result\n";
echo "Key 0 exists: " . (isset($j[0]) ? "yes" : "no") . "\n";

// Large positive amount
$result = $j->increment(1, 1000000);
echo "increment(1, 1000000): $result\n";

// Large negative amount
$result = $j->increment(2, -999999);
echo "increment(2, -999999): $result\n";

// Multiple increments accumulate
$j->increment(5, 100);
$j->increment(5, 200);
$j->increment(5, 300);
echo "After 3 increments of 100+200+300: " . $j[5] . "\n";

// Increment then decrement back to 0
$j->increment(10, 50);
$result = $j->increment(10, -50);
echo "Increment then decrement to 0: $result\n";

// STRING_TO_INT: same boundary tests
$j = new Judy(Judy::STRING_TO_INT);

$result = $j->increment("key", 0);
echo "STRING increment by 0: $result\n";

$j->increment("counter", 1000000);
$j->increment("counter", -500000);
echo "STRING counter after +1M -500K: " . $j["counter"] . "\n";

echo "Count: " . $j->count() . "\n";
?>
--EXPECT--
increment(0, 0): 0
Key 0 exists: yes
increment(1, 1000000): 1000000
increment(2, -999999): -999999
After 3 increments of 100+200+300: 600
Increment then decrement to 0: 0
STRING increment by 0: 0
STRING counter after +1M -500K: 500000
Count: 2
