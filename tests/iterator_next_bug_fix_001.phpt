--TEST--
Test Iterator next() method to catch potential bugs on edge cases
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
echo "Testing Iterator next() method for bug prevention:\n\n";

// Test 1: INT_TO_INT - multiple next() calls to reach end
echo "=== Testing INT_TO_INT multiple next() calls ===\n";
$judy1 = new Judy(Judy::INT_TO_INT);
$judy1[1] = 'one';
$judy1[2] = 'two';

$judy1->rewind();
echo "After rewind - key: " . $judy1->key() . ", current: " . $judy1->current() . "\n";

$judy1->next();
echo "After first next() - key: " . $judy1->key() . ", current: " . $judy1->current() . "\n";

$judy1->next();
echo "After second next() - valid(): " . ($judy1->valid() ? 'true' : 'false') . "\n";

// Test 2: INT_TO_MIXED - multiple next() calls to reach end
echo "\n=== Testing INT_TO_MIXED multiple next() calls ===\n";
$judy2 = new Judy(Judy::INT_TO_MIXED);
$judy2[10] = 'ten';
$judy2[20] = 'twenty';

$judy2->rewind();
echo "After rewind - key: " . $judy2->key() . ", current: " . $judy2->current() . "\n";

$judy2->next();
echo "After first next() - key: " . $judy2->key() . ", current: " . $judy2->current() . "\n";

$judy2->next();
echo "After second next() - valid(): " . ($judy2->valid() ? 'true' : 'false') . "\n";

// Test 3: Empty INT_TO_INT - next() after rewind
echo "\n=== Testing empty INT_TO_INT next() after rewind ===\n";
$judy3 = new Judy(Judy::INT_TO_INT);
$judy3->rewind();
echo "After rewind on empty - valid(): " . ($judy3->valid() ? 'true' : 'false') . "\n";

$judy3->next();
echo "After next() on empty - valid(): " . ($judy3->valid() ? 'true' : 'false') . "\n";
echo "After next() on empty - key(): ";
var_dump($judy3->key());
echo "After next() on empty - current(): ";
var_dump($judy3->current());

// Test 4: Empty INT_TO_MIXED - next() after rewind
echo "\n=== Testing empty INT_TO_MIXED next() after rewind ===\n";
$judy4 = new Judy(Judy::INT_TO_MIXED);
$judy4->rewind();
echo "After rewind on empty - valid(): " . ($judy4->valid() ? 'true' : 'false') . "\n";

$judy4->next();
echo "After next() on empty - valid(): " . ($judy4->valid() ? 'true' : 'false') . "\n";
echo "After next() on empty - key(): ";
var_dump($judy4->key());
echo "After next() on empty - current(): ";
var_dump($judy4->current());

// Test 5: Single element - next() should reach end
echo "\n=== Testing single element next() ===\n";
$judy5 = new Judy(Judy::INT_TO_INT);
$judy5[5] = 'five';

$judy5->rewind();
echo "After rewind - key: " . $judy5->key() . ", current: " . $judy5->current() . "\n";

$judy5->next();
echo "After next() - valid(): " . ($judy5->valid() ? 'true' : 'false') . "\n";
echo "After next() - key(): ";
var_dump($judy5->key());
echo "After next() - current(): ";
var_dump($judy5->current());

// Test 6: Multiple next() calls beyond end
echo "\n=== Testing multiple next() calls beyond end ===\n";
$judy6 = new Judy(Judy::INT_TO_INT);
$judy6[1] = 'one';

$judy6->rewind();
echo "After rewind - valid(): " . ($judy6->valid() ? 'true' : 'false') . "\n";

$judy6->next();
echo "After first next() - valid(): " . ($judy6->valid() ? 'true' : 'false') . "\n";

$judy6->next();
echo "After second next() - valid(): " . ($judy6->valid() ? 'true' : 'false') . "\n";

$judy6->next();
echo "After third next() - valid(): " . ($judy6->valid() ? 'true' : 'false') . "\n";

// Test 7: Mixed operations - add data, iterate, remove data, iterate
echo "\n=== Testing mixed operations ===\n";
$judy7 = new Judy(Judy::INT_TO_INT);
$judy7->rewind();
echo "After rewind on empty - valid(): " . ($judy7->valid() ? 'true' : 'false') . "\n";

$judy7[3] = 'three';
$judy7->rewind();
echo "After rewind with data - valid(): " . ($judy7->valid() ? 'true' : 'false') . "\n";

$judy7->next();
echo "After next() with data - valid(): " . ($judy7->valid() ? 'true' : 'false') . "\n";

unset($judy7[3]);
$judy7->rewind();
echo "After rewind after removal - valid(): " . ($judy7->valid() ? 'true' : 'false') . "\n";

$judy7->next();
echo "After next() after removal - valid(): " . ($judy7->valid() ? 'true' : 'false') . "\n";

echo "\nAll tests completed without segmentation fault!\n";
?>
--EXPECT--
Testing Iterator next() method for bug prevention:

=== Testing INT_TO_INT multiple next() calls ===
After rewind - key: 1, current: 0
After first next() - key: 2, current: 0
After second next() - valid(): false

=== Testing INT_TO_MIXED multiple next() calls ===
After rewind - key: 10, current: ten
After first next() - key: 20, current: twenty
After second next() - valid(): false

=== Testing empty INT_TO_INT next() after rewind ===
After rewind on empty - valid(): false
After next() on empty - valid(): false
After next() on empty - key(): NULL
After next() on empty - current(): NULL

=== Testing empty INT_TO_MIXED next() after rewind ===
After rewind on empty - valid(): false
After next() on empty - valid(): false
After next() on empty - key(): NULL
After next() on empty - current(): NULL

=== Testing single element next() ===
After rewind - key: 5, current: 0
After next() - valid(): false
After next() - key(): NULL
After next() - current(): NULL

=== Testing multiple next() calls beyond end ===
After rewind - valid(): true
After first next() - valid(): false
After second next() - valid(): false
After third next() - valid(): false

=== Testing mixed operations ===
After rewind on empty - valid(): false
After rewind with data - valid(): true
After next() with data - valid(): false
After rewind after removal - valid(): false
After next() after removal - valid(): false

All tests completed without segmentation fault!
