--TEST--
Judy clone STRING_TO_MIXED independence
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::STRING_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED);
$j["name"] = "John";
$j["data"] = [1, 2, 3];
$j["count"] = 42;

$clone = clone $j;

// Verify clone has same data
echo "Clone count: " . $clone->count() . "\n";
echo "Clone[name]: " . $clone["name"] . "\n";
echo "Clone[data]: " . implode(",", $clone["data"]) . "\n";
echo "Clone[count]: " . $clone["count"] . "\n";

// Modify original, verify clone unchanged
$j["name"] = "Jane";
unset($j["data"]);

echo "After modify - original count: " . $j->count() . "\n";
echo "After modify - clone count: " . $clone->count() . "\n";
echo "Original[name]: " . $j["name"] . "\n";
echo "Clone[name]: " . $clone["name"] . "\n";
echo "Clone[data]: " . implode(",", $clone["data"]) . "\n";
?>
--EXPECT--
Clone count: 3
Clone[name]: John
Clone[data]: 1,2,3
Clone[count]: 42
After modify - original count: 2
After modify - clone count: 3
Original[name]: Jane
Clone[name]: John
Clone[data]: 1,2,3
