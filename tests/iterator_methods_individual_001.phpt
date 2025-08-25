--TEST--
Test individual Iterator interface methods (rewind, valid, current, key, next)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
echo "Testing individual Iterator interface methods:\n\n";

// Test 1: rewind() method
echo "=== Testing rewind() method ===\n";
$judy1 = new Judy(Judy::INT_TO_INT);
$judy1[10] = 'ten';
$judy1[20] = 'twenty';

echo "Before rewind - valid(): " . ($judy1->valid() ? 'true' : 'false') . "\n";
$judy1->rewind();
echo "After rewind - valid(): " . ($judy1->valid() ? 'true' : 'false') . "\n";
echo "After rewind - key(): " . $judy1->key() . "\n";
echo "After rewind - current(): " . $judy1->current() . "\n";

// Test 2: valid() method
echo "\n=== Testing valid() method ===\n";
$judy2 = new Judy(Judy::INT_TO_INT);
echo "Empty Judy - valid(): " . ($judy2->valid() ? 'true' : 'false') . "\n";
$judy2->rewind();
echo "Empty Judy after rewind - valid(): " . ($judy2->valid() ? 'true' : 'false') . "\n";

$judy2[5] = 'five';
$judy2->rewind();
echo "Non-empty Judy after rewind - valid(): " . ($judy2->valid() ? 'true' : 'false') . "\n";

// Test 3: current() method
echo "\n=== Testing current() method ===\n";
$judy3 = new Judy(Judy::INT_TO_INT);
echo "Empty Judy - current(): ";
var_dump($judy3->current());

$judy3[15] = 'fifteen';
$judy3->rewind();
echo "Non-empty Judy after rewind - current(): " . $judy3->current() . "\n";

// Test 4: key() method
echo "\n=== Testing key() method ===\n";
$judy4 = new Judy(Judy::INT_TO_INT);
echo "Empty Judy - key(): ";
var_dump($judy4->key());

$judy4[25] = 'twenty-five';
$judy4->rewind();
echo "Non-empty Judy after rewind - key(): " . $judy4->key() . "\n";

// Test 5: next() method
echo "\n=== Testing next() method ===\n";
$judy5 = new Judy(Judy::INT_TO_INT);
$judy5[1] = 'one';
$judy5[2] = 'two';

$judy5->rewind();
echo "After rewind - key: " . $judy5->key() . ", current: " . $judy5->current() . "\n";

$judy5->next();
echo "After first next() - key: " . $judy5->key() . ", current: " . $judy5->current() . "\n";

$judy5->next();
echo "After second next() - valid(): " . ($judy5->valid() ? 'true' : 'false') . "\n";

// Test 6: Multiple rewind() calls
echo "\n=== Testing multiple rewind() calls ===\n";
$judy6 = new Judy(Judy::INT_TO_INT);
$judy6[100] = 'hundred';
$judy6[200] = 'two-hundred';

$judy6->rewind();
echo "First rewind - key: " . $judy6->key() . "\n";

$judy6->next();
echo "After next - key: " . $judy6->key() . "\n";

$judy6->rewind();
echo "Second rewind - key: " . $judy6->key() . "\n";

// Test 7: Different Judy types
echo "\n=== Testing different Judy types ===\n";

// STRING_TO_INT
$judy7 = new Judy(Judy::STRING_TO_INT);
$judy7['a'] = 1;
$judy7->rewind();
echo "STRING_TO_INT - key: " . $judy7->key() . ", current: " . $judy7->current() . "\n";

// BITSET
$judy8 = new Judy(Judy::BITSET);
$judy8[5] = true;
$judy8->rewind();
echo "BITSET - key: " . $judy8->key() . ", current: " . ($judy8->current() ? 'true' : 'false') . "\n";
?>
--EXPECT--
Testing individual Iterator interface methods:

=== Testing rewind() method ===
Before rewind - valid(): false
After rewind - valid(): true
After rewind - key(): 10
After rewind - current(): 0

=== Testing valid() method ===
Empty Judy - valid(): false
Empty Judy after rewind - valid(): false
Non-empty Judy after rewind - valid(): true

=== Testing current() method ===
Empty Judy - current(): NULL
Non-empty Judy after rewind - current(): 0

=== Testing key() method ===
Empty Judy - key(): NULL
Non-empty Judy after rewind - key(): 25

=== Testing next() method ===
After rewind - key: 1, current: 0
After first next() - key: 2, current: 0
After second next() - valid(): false

=== Testing multiple rewind() calls ===
First rewind - key: 100
After next - key: 200
Second rewind - key: 100

=== Testing different Judy types ===
STRING_TO_INT - key: a, current: 1
BITSET - key: 5, current: true
