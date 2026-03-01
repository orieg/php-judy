--TEST--
Judy counter correctness after putAll for all integer-keyed types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// BITSET: putAll then count
$j = new Judy(Judy::BITSET);
$j->putAll([5, 10, 15, 20, 25]);
var_dump(count($j));  // 5

// Duplicate insert should not double-count
$j->putAll([10, 15, 30]);
var_dump(count($j));  // 6

// INT_TO_INT: putAll then count
$j2 = new Judy(Judy::INT_TO_INT);
$j2->putAll([0 => 100, 1 => 200, 2 => 300]);
var_dump(count($j2));  // 3

// Overwrite existing key should not double-count
$j2->putAll([1 => 999, 3 => 400]);
var_dump(count($j2));  // 4
var_dump($j2[1]);      // 999 (overwritten)

// INT_TO_MIXED: putAll then count
$j3 = new Judy(Judy::INT_TO_MIXED);
$j3->putAll([0 => "hello", 1 => 42, 2 => [1,2,3]]);
var_dump(count($j3));  // 3

// Overwrite
$j3->putAll([1 => "replaced"]);
var_dump(count($j3));  // 3
var_dump($j3[1]);      // "replaced"

// INT_TO_PACKED: putAll then count
$j4 = new Judy(Judy::INT_TO_PACKED);
$j4->putAll([0 => "hello", 1 => 42, 2 => 3.14, 3 => true, 4 => null]);
var_dump(count($j4));  // 5

// Overwrite
$j4->putAll([2 => "overwritten", 5 => false]);
var_dump(count($j4));  // 6
var_dump($j4[2]);      // "overwritten"

echo "OK\n";
?>
--EXPECT--
int(5)
int(6)
int(3)
int(4)
int(999)
int(3)
int(3)
string(8) "replaced"
int(5)
int(6)
string(11) "overwritten"
OK
