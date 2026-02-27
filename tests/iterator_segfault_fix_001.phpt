--TEST--
Test Iterator next() method to catch potential segmentation fault
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
echo "Testing Iterator next() method for segfault prevention:\n\n";

// Test 1: INT_TO_INT - call next() without rewind() first
echo "=== Testing INT_TO_INT next() without rewind() ===\n";
$judy1 = new Judy(Judy::INT_TO_INT);
$judy1[1] = 'one';
$judy1[2] = 'two';

echo "Calling next() without rewind() first...\n";
$judy1->next(); // This should not segfault
echo "next() completed successfully\n";
echo "Current state - valid(): " . ($judy1->valid() ? 'true' : 'false') . "\n";

// Test 2: INT_TO_MIXED - call next() without rewind() first
echo "\n=== Testing INT_TO_MIXED next() without rewind() ===\n";
$judy2 = new Judy(Judy::INT_TO_MIXED);
$judy2[1] = 'one';
$judy2[2] = 'two';

echo "Calling next() without rewind() first...\n";
$judy2->next(); // This should not segfault
echo "next() completed successfully\n";
echo "Current state - valid(): " . ($judy2->valid() ? 'true' : 'false') . "\n";

// Test 3: Empty INT_TO_INT - call next() without rewind()
echo "\n=== Testing empty INT_TO_INT next() without rewind() ===\n";
$judy3 = new Judy(Judy::INT_TO_INT);
echo "Calling next() on empty Judy without rewind()...\n";
$judy3->next(); // This should not segfault
echo "next() completed successfully\n";
echo "Current state - valid(): " . ($judy3->valid() ? 'true' : 'false') . "\n";

// Test 4: Multiple next() calls without rewind()
echo "\n=== Testing multiple next() calls without rewind() ===\n";
$judy4 = new Judy(Judy::INT_TO_INT);
$judy4[1] = 'one';
$judy4[2] = 'two';

echo "First next() call...\n";
$judy4->next();
echo "Second next() call...\n";
$judy4->next();
echo "Third next() call...\n";
$judy4->next();
echo "All next() calls completed successfully\n";

// Test 5: Mixed operations - rewind, next, next without rewind again
echo "\n=== Testing mixed operations ===\n";
$judy5 = new Judy(Judy::INT_TO_INT);
$judy5[1] = 'one';
$judy5[2] = 'two';

echo "rewind()...\n";
$judy5->rewind();
echo "next()...\n";
$judy5->next();
echo "next() again without rewind...\n";
$judy5->next();
echo "Mixed operations completed successfully\n";

echo "\nAll tests completed without segmentation fault!\n";
?>
--EXPECT--
Testing Iterator next() method for segfault prevention:

=== Testing INT_TO_INT next() without rewind() ===
Calling next() without rewind() first...
next() completed successfully
Current state - valid(): false

=== Testing INT_TO_MIXED next() without rewind() ===
Calling next() without rewind() first...
next() completed successfully
Current state - valid(): false

=== Testing empty INT_TO_INT next() without rewind() ===
Calling next() on empty Judy without rewind()...
next() completed successfully
Current state - valid(): false

=== Testing multiple next() calls without rewind() ===
First next() call...
Second next() call...
Third next() call...
All next() calls completed successfully

=== Testing mixed operations ===
rewind()...
next()...
next() again without rewind...
Mixed operations completed successfully

All tests completed without segmentation fault!
