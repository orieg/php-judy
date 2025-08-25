--TEST--
Test that next() doesn't act like rewind() when iterator is invalid
--DESCRIPTION--
This test verifies that calling next() on an invalid iterator (after reaching the end)
does not reset the iterator to the beginning. Once an iterator is invalid, it should
remain invalid until rewind() is explicitly called.
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php

$judy = new Judy(Judy::INT_TO_INT);

// Add some elements
$judy[1] = 10;
$judy[2] = 20;
$judy[3] = 30;

echo "=== Testing next() behavior on invalid iterator ===\n";

// Start iteration
$judy->rewind();
echo "After rewind(): valid=" . ($judy->valid() ? 'true' : 'false') . ", key=" . $judy->key() . ", current=" . $judy->current() . "\n";

// Advance to end
$judy->next(); // 2
echo "After first next(): valid=" . ($judy->valid() ? 'true' : 'false') . ", key=" . $judy->key() . ", current=" . $judy->current() . "\n";

$judy->next(); // 3
echo "After second next(): valid=" . ($judy->valid() ? 'true' : 'false') . ", key=" . $judy->key() . ", current=" . $judy->current() . "\n";

$judy->next(); // Should be invalid now
echo "After third next(): valid=" . ($judy->valid() ? 'true' : 'false') . "\n";

// This is the critical test - calling next() on invalid iterator should NOT reset it
$judy->next();
echo "After fourth next() (on invalid): valid=" . ($judy->valid() ? 'true' : 'false') . "\n";

$judy->next();
echo "After fifth next() (on invalid): valid=" . ($judy->valid() ? 'true' : 'false') . "\n";

// Only rewind() should make it valid again
$judy->rewind();
echo "After rewind(): valid=" . ($judy->valid() ? 'true' : 'false') . ", key=" . $judy->key() . ", current=" . $judy->current() . "\n";

echo "=== Test completed ===\n";
?>
--EXPECT--
=== Testing next() behavior on invalid iterator ===
After rewind(): valid=true, key=1, current=10
After first next(): valid=true, key=2, current=20
After second next(): valid=true, key=3, current=30
After third next(): valid=false
After fourth next() (on invalid): valid=false
After fifth next() (on invalid): valid=false
After rewind(): valid=true, key=1, current=10
=== Test completed ===
