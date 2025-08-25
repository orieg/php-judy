--TEST--
Test Iterator interface methods implementation (simple)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
echo "Testing Iterator interface methods (simple):\n\n";

// Test basic Iterator functionality
$judy = new Judy(Judy::INT_TO_INT);
$judy[1] = 'one';
$judy[2] = 'two';

echo "Testing basic iteration:\n";
$judy->rewind();
while ($judy->valid()) {
    echo $judy->key() . ' => ' . $judy->current() . "\n";
    $judy->next();
}

echo "\nTesting instanceof:\n";
echo "Instanceof Iterator: " . (($judy instanceof Iterator) ? 'true' : 'false') . "\n";
echo "Instanceof Traversable: " . (($judy instanceof Traversable) ? 'true' : 'false') . "\n";

echo "\nTesting empty Judy:\n";
$empty_judy = new Judy(Judy::INT_TO_INT);
echo "Empty Judy valid(): " . ($empty_judy->valid() ? 'true' : 'false') . "\n";
$empty_judy->rewind();
echo "Empty Judy after rewind valid(): " . ($empty_judy->valid() ? 'true' : 'false') . "\n";

echo "\nTesting method calls on empty state:\n";
$test_judy = new Judy(Judy::INT_TO_INT);
echo "Current before rewind: ";
var_dump($test_judy->current());
echo "Key before rewind: ";
var_dump($test_judy->key());
echo "Valid before rewind: ";
var_dump($test_judy->valid());

$test_judy->rewind();
echo "Current after rewind (empty): ";
var_dump($test_judy->current());
echo "Key after rewind (empty): ";
var_dump($test_judy->key());
echo "Valid after rewind (empty): ";
var_dump($test_judy->valid());
?>
--EXPECT--
Testing Iterator interface methods (simple):

Testing basic iteration:
1 => 0
2 => 0

Testing instanceof:
Instanceof Iterator: true
Instanceof Traversable: true

Testing empty Judy:
Empty Judy valid(): false
Empty Judy after rewind valid(): false

Testing method calls on empty state:
Current before rewind: NULL
Key before rewind: NULL
Valid before rewind: bool(false)
Current after rewind (empty): NULL
Key after rewind (empty): NULL
Valid after rewind (empty): bool(false)
