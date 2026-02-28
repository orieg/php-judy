--TEST--
Judy increment for INT_TO_INT: basic, custom amount, new key auto-creates
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);

// New key starts at 0, increment creates it with value = amount
$result = $j->increment(5);
echo "New key increment(5): $result\n";
echo "Value at 5: " . $j[5] . "\n";

// Increment existing key
$result = $j->increment(5);
echo "Second increment(5): $result\n";
echo "Value at 5: " . $j[5] . "\n";

// Custom amount
$result = $j->increment(5, 10);
echo "increment(5, 10): $result\n";
echo "Value at 5: " . $j[5] . "\n";

// Negative amount (decrement)
$result = $j->increment(5, -3);
echo "increment(5, -3): $result\n";
echo "Value at 5: " . $j[5] . "\n";

// Another new key with custom amount
$result = $j->increment(100, 50);
echo "New key increment(100, 50): $result\n";
echo "Value at 100: " . $j[100] . "\n";

echo "Count: " . $j->count() . "\n";
?>
--EXPECT--
New key increment(5): 1
Value at 5: 1
Second increment(5): 2
Value at 5: 2
increment(5, 10): 12
Value at 5: 12
increment(5, -3): 9
Value at 5: 9
New key increment(100, 50): 50
Value at 100: 50
Count: 2
