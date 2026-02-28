--TEST--
Check for Judy STRING_TO_MIXED_HASH __clone() method
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_MIXED_HASH);
$judy["name"]  = "Alice";
$judy["score"] = 99;
$judy["tags"]  = ["php", "judy"];

$judy2 = clone $judy;

// Both should have the same values
var_dump($judy["name"]);
var_dump($judy2["name"]);

var_dump($judy["score"]);
var_dump($judy2["score"]);

var_dump($judy["tags"]);
var_dump($judy2["tags"]);

// Modifying clone must not affect original
$judy2["name"] = "Bob";
echo "Original name: " . $judy["name"] . "\n";
echo "Clone name: " . $judy2["name"] . "\n";

// Count must match
echo "Original count: " . $judy->count() . "\n";
echo "Clone count: " . $judy2->count() . "\n";

echo "Done\n";
?>
--EXPECT--
string(5) "Alice"
string(5) "Alice"
int(99)
int(99)
array(2) {
  [0]=>
  string(3) "php"
  [1]=>
  string(4) "judy"
}
array(2) {
  [0]=>
  string(3) "php"
  [1]=>
  string(4) "judy"
}
Original name: Alice
Clone name: Bob
Original count: 3
Clone count: 3
Done
