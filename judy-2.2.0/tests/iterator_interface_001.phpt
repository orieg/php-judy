--TEST--
Judy class implements Iterator interface
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$judy = new Judy(Judy::INT_TO_INT);

var_dump($judy instanceof Traversable);
var_dump($judy instanceof Iterator);
var_dump($judy instanceof ArrayAccess);
var_dump($judy instanceof Countable);

// From GitHub issue #25, ensure it works with SPL iterators
try {
    $it = new LimitIterator($judy, 0, 10);
    echo "LimitIterator created successfully\n";
} catch (TypeError $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
LimitIterator created successfully
