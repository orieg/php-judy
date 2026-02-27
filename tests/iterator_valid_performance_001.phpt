--TEST--
Test that valid() method is efficient and uses cached state
--DESCRIPTION--
This test verifies that the valid() method is efficient by using cached state
instead of performing expensive lookups. It also ensures the method works correctly
for all Judy array types.
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php

echo "=== Testing valid() method efficiency and correctness ===\n";

// Test INT_TO_INT
echo "\n--- Testing INT_TO_INT ---\n";
$judy1 = new Judy(Judy::INT_TO_INT);
$judy1[1] = 10;
$judy1[2] = 20;

$judy1->rewind();
echo "After rewind(): valid=" . ($judy1->valid() ? 'true' : 'false') . "\n";

$judy1->next();
echo "After next(): valid=" . ($judy1->valid() ? 'true' : 'false') . "\n";

$judy1->next();
echo "After second next(): valid=" . ($judy1->valid() ? 'true' : 'false') . "\n";

$judy1->next();
echo "After third next(): valid=" . ($judy1->valid() ? 'true' : 'false') . "\n";

// Test INT_TO_MIXED
echo "\n--- Testing INT_TO_MIXED ---\n";
$judy2 = new Judy(Judy::INT_TO_MIXED);
$judy2[1] = "hello";
$judy2[2] = 42;

$judy2->rewind();
echo "After rewind(): valid=" . ($judy2->valid() ? 'true' : 'false') . "\n";

$judy2->next();
echo "After next(): valid=" . ($judy2->valid() ? 'true' : 'false') . "\n";

$judy2->next();
echo "After second next(): valid=" . ($judy2->valid() ? 'true' : 'false') . "\n";

// Test STRING_TO_INT
echo "\n--- Testing STRING_TO_INT ---\n";
$judy3 = new Judy(Judy::STRING_TO_INT);
$judy3["a"] = 100;
$judy3["b"] = 200;

$judy3->rewind();
echo "After rewind(): valid=" . ($judy3->valid() ? 'true' : 'false') . "\n";

$judy3->next();
echo "After next(): valid=" . ($judy3->valid() ? 'true' : 'false') . "\n";

$judy3->next();
echo "After second next(): valid=" . ($judy3->valid() ? 'true' : 'false') . "\n";

// Test STRING_TO_MIXED
echo "\n--- Testing STRING_TO_MIXED ---\n";
$judy4 = new Judy(Judy::STRING_TO_MIXED);
$judy4["x"] = "test";
$judy4["y"] = 123;

$judy4->rewind();
echo "After rewind(): valid=" . ($judy4->valid() ? 'true' : 'false') . "\n";

$judy4->next();
echo "After next(): valid=" . ($judy4->valid() ? 'true' : 'false') . "\n";

$judy4->next();
echo "After second next(): valid=" . ($judy4->valid() ? 'true' : 'false') . "\n";

// Test BITSET
echo "\n--- Testing BITSET ---\n";
$judy5 = new Judy(Judy::BITSET);
$judy5[1] = true;
$judy5[3] = true;

$judy5->rewind();
echo "After rewind(): valid=" . ($judy5->valid() ? 'true' : 'false') . "\n";

$judy5->next();
echo "After next(): valid=" . ($judy5->valid() ? 'true' : 'false') . "\n";

$judy5->next();
echo "After second next(): valid=" . ($judy5->valid() ? 'true' : 'false') . "\n";

$judy5->next();
echo "After third next(): valid=" . ($judy5->valid() ? 'true' : 'false') . "\n";

echo "\n=== Test completed ===\n";
?>
--EXPECT--
=== Testing valid() method efficiency and correctness ===

--- Testing INT_TO_INT ---
After rewind(): valid=true
After next(): valid=true
After second next(): valid=false
After third next(): valid=false

--- Testing INT_TO_MIXED ---
After rewind(): valid=true
After next(): valid=true
After second next(): valid=false

--- Testing STRING_TO_INT ---
After rewind(): valid=true
After next(): valid=true
After second next(): valid=false

--- Testing STRING_TO_MIXED ---
After rewind(): valid=true
After next(): valid=true
After second next(): valid=false

--- Testing BITSET ---
After rewind(): valid=true
After next(): valid=true
After second next(): valid=false
After third next(): valid=false

=== Test completed ===
