--TEST--
Test Judy STRING_TO_INT counter correctness on overwrite
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_INT);

// Insert three keys
$judy["foo"] = 1;
$judy["bar"] = 2;
$judy["baz"] = 3;
echo "Count after 3 inserts: " . $judy->count() . "\n";

// Overwrite existing keys - count should NOT increase
$judy["foo"] = 10;
$judy["bar"] = 20;
echo "Count after 2 overwrites: " . $judy->count() . "\n";

// Overwrite with zero - count should NOT increase
$judy["baz"] = 0;
echo "Count after overwrite with 0: " . $judy->count() . "\n";

// Insert a new key
$judy["qux"] = 4;
echo "Count after 1 new insert: " . $judy->count() . "\n";

// Verify values
echo "foo=" . $judy["foo"] . "\n";
echo "bar=" . $judy["bar"] . "\n";
echo "baz=" . $judy["baz"] . "\n";
echo "qux=" . $judy["qux"] . "\n";
?>
--EXPECT--
Count after 3 inserts: 3
Count after 2 overwrites: 3
Count after overwrite with 0: 3
Count after 1 new insert: 4
foo=10
bar=20
baz=0
qux=4
