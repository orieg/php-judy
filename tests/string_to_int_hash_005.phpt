--TEST--
Check for Judy STRING_TO_INT_HASH __clone() method
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_INT_HASH);
$judy["name"]  = 100;
$judy["score"] = 99;
$judy["level"] = 5;

$judy2 = clone $judy;

// Both should have the same values
var_dump($judy["name"]);
var_dump($judy2["name"]);

var_dump($judy["score"]);
var_dump($judy2["score"]);

var_dump($judy["level"]);
var_dump($judy2["level"]);

// Modifying clone must not affect original
$judy2["name"] = 200;
echo "Original name: " . $judy["name"] . "\n";
echo "Clone name: " . $judy2["name"] . "\n";

// Count must match
echo "Original count: " . $judy->count() . "\n";
echo "Clone count: " . $judy2->count() . "\n";

echo "Done\n";
?>
--EXPECT--
int(100)
int(100)
int(99)
int(99)
int(5)
int(5)
Original name: 100
Clone name: 200
Original count: 3
Clone count: 3
Done
