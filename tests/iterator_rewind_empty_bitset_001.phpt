--TEST--
Test that rewind() correctly handles empty BITSET arrays
--DESCRIPTION--
This test verifies that calling rewind() on an empty BITSET array correctly marks
the iterator as invalid instead of incorrectly marking it as valid.
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php

echo "=== Testing rewind() on empty BITSET array ===\n";

$judy = new Judy(Judy::BITSET);

// Test rewind() on empty array
echo "Before rewind(): valid=" . ($judy->valid() ? 'true' : 'false') . "\n";

$judy->rewind();
echo "After rewind(): valid=" . ($judy->valid() ? 'true' : 'false') . "\n";

// Test next() on empty array
$judy->next();
echo "After next(): valid=" . ($judy->valid() ? 'true' : 'false') . "\n";

// Test foreach on empty array
echo "Testing foreach on empty array:\n";
$count = 0;
foreach ($judy as $key => $value) {
    echo "  Found: key=$key, value=$value\n";
    $count++;
}
echo "Foreach completed, count=$count\n";

// Add an element and test again
echo "\n=== Testing with one element ===\n";
$judy[5] = true;

$judy->rewind();
echo "After rewind(): valid=" . ($judy->valid() ? 'true' : 'false') . ", key=" . $judy->key() . ", current=" . ($judy->current() ? 'true' : 'false') . "\n";

$judy->next();
echo "After next(): valid=" . ($judy->valid() ? 'true' : 'false') . "\n";

echo "=== Test completed ===\n";
?>
--EXPECT--
=== Testing rewind() on empty BITSET array ===
Before rewind(): valid=false
After rewind(): valid=false
After next(): valid=false
Testing foreach on empty array:
Foreach completed, count=0

=== Testing with one element ===
After rewind(): valid=true, key=5, current=true
After next(): valid=false
=== Test completed ===
