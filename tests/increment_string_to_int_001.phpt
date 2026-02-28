--TEST--
Judy increment for STRING_TO_INT: basic, counter tracking
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_INT);

// New key
$result = $j->increment("apple");
echo "New key increment('apple'): $result\n";
echo "Count: " . $j->count() . "\n";

// Increment existing
$result = $j->increment("apple");
echo "Second increment('apple'): $result\n";
echo "Count: " . $j->count() . "\n";

// Another new key with custom amount
$result = $j->increment("banana", 5);
echo "increment('banana', 5): $result\n";
echo "Count: " . $j->count() . "\n";

// Verify values
echo "apple: " . $j["apple"] . "\n";
echo "banana: " . $j["banana"] . "\n";
?>
--EXPECT--
New key increment('apple'): 1
Count: 1
Second increment('apple'): 2
Count: 1
increment('banana', 5): 5
Count: 2
apple: 2
banana: 5
