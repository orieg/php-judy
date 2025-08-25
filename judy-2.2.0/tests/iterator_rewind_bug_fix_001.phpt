--TEST--
Test Iterator rewind() method to catch potential bugs on empty arrays
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
echo "Testing Iterator rewind() method for bug prevention:\n\n";

// Test 1: Empty INT_TO_INT - rewind() should not segfault
echo "=== Testing empty INT_TO_INT rewind() ===\n";
$judy1 = new Judy(Judy::INT_TO_INT);
echo "Calling rewind() on empty INT_TO_INT...\n";
$judy1->rewind();
echo "rewind() completed successfully\n";
echo "Current state - valid(): " . ($judy1->valid() ? 'true' : 'false') . "\n";
echo "Current state - key(): ";
var_dump($judy1->key());
echo "Current state - current(): ";
var_dump($judy1->current());

// Test 2: Empty INT_TO_MIXED - rewind() should not segfault
echo "\n=== Testing empty INT_TO_MIXED rewind() ===\n";
$judy2 = new Judy(Judy::INT_TO_MIXED);
echo "Calling rewind() on empty INT_TO_MIXED...\n";
$judy2->rewind();
echo "rewind() completed successfully\n";
echo "Current state - valid(): " . ($judy2->valid() ? 'true' : 'false') . "\n";
echo "Current state - key(): ";
var_dump($judy2->key());
echo "Current state - current(): ";
var_dump($judy2->current());

// Test 3: Non-empty INT_TO_INT - rewind() should work correctly
echo "\n=== Testing non-empty INT_TO_INT rewind() ===\n";
$judy3 = new Judy(Judy::INT_TO_INT);
$judy3[10] = 'ten';
$judy3[20] = 'twenty';
echo "Calling rewind() on non-empty INT_TO_INT...\n";
$judy3->rewind();
echo "rewind() completed successfully\n";
echo "Current state - valid(): " . ($judy3->valid() ? 'true' : 'false') . "\n";
echo "Current state - key(): " . $judy3->key() . "\n";
echo "Current state - current(): " . $judy3->current() . "\n";

// Test 4: Non-empty INT_TO_MIXED - rewind() should work correctly
echo "\n=== Testing non-empty INT_TO_MIXED rewind() ===\n";
$judy4 = new Judy(Judy::INT_TO_MIXED);
$judy4[15] = 'fifteen';
$judy4[25] = 'twenty-five';
echo "Calling rewind() on non-empty INT_TO_MIXED...\n";
$judy4->rewind();
echo "rewind() completed successfully\n";
echo "Current state - valid(): " . ($judy4->valid() ? 'true' : 'false') . "\n";
echo "Current state - key(): " . $judy4->key() . "\n";
echo "Current state - current(): " . $judy4->current() . "\n";

// Test 5: Multiple rewind() calls on empty array
echo "\n=== Testing multiple rewind() calls on empty array ===\n";
$judy5 = new Judy(Judy::INT_TO_INT);
echo "First rewind() call...\n";
$judy5->rewind();
echo "Second rewind() call...\n";
$judy5->rewind();
echo "Third rewind() call...\n";
$judy5->rewind();
echo "All rewind() calls completed successfully\n";

// Test 6: Mixed operations - add data, rewind, remove data, rewind
echo "\n=== Testing mixed operations ===\n";
$judy6 = new Judy(Judy::INT_TO_INT);
echo "rewind() on empty array...\n";
$judy6->rewind();
echo "Adding data...\n";
$judy6[5] = 'five';
echo "rewind() on non-empty array...\n";
$judy6->rewind();
echo "Removing data...\n";
unset($judy6[5]);
echo "rewind() on empty array again...\n";
$judy6->rewind();
echo "Mixed operations completed successfully\n";

echo "\nAll tests completed without segmentation fault!\n";
?>
--EXPECT--
Testing Iterator rewind() method for bug prevention:

=== Testing empty INT_TO_INT rewind() ===
Calling rewind() on empty INT_TO_INT...
rewind() completed successfully
Current state - valid(): false
Current state - key(): NULL
Current state - current(): NULL

=== Testing empty INT_TO_MIXED rewind() ===
Calling rewind() on empty INT_TO_MIXED...
rewind() completed successfully
Current state - valid(): false
Current state - key(): NULL
Current state - current(): NULL

=== Testing non-empty INT_TO_INT rewind() ===
Calling rewind() on non-empty INT_TO_INT...
rewind() completed successfully
Current state - valid(): true
Current state - key(): 10
Current state - current(): 0

=== Testing non-empty INT_TO_MIXED rewind() ===
Calling rewind() on non-empty INT_TO_MIXED...
rewind() completed successfully
Current state - valid(): true
Current state - key(): 15
Current state - current(): fifteen

=== Testing multiple rewind() calls on empty array ===
First rewind() call...
Second rewind() call...
Third rewind() call...
All rewind() calls completed successfully

=== Testing mixed operations ===
rewind() on empty array...
Adding data...
rewind() on non-empty array...
Removing data...
rewind() on empty array again...
Mixed operations completed successfully

All tests completed without segmentation fault!
