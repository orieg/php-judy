--TEST--
Judy fromArray roundtrip for all 5 types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// BITSET
$j = Judy::fromArray(Judy::BITSET, [1, 5, 10, 100]);
echo "BITSET count: " . $j->count() . "\n";
var_dump($j->toArray());

// INT_TO_INT
$j = Judy::fromArray(Judy::INT_TO_INT, [0 => 100, 5 => 200, 10 => 300]);
echo "INT_TO_INT count: " . $j->count() . "\n";
var_dump($j->toArray());

// INT_TO_MIXED
$j = Judy::fromArray(Judy::INT_TO_MIXED, [0 => "hello", 5 => 42]);
echo "INT_TO_MIXED count: " . $j->count() . "\n";
var_dump($j->toArray());

// STRING_TO_INT
$j = Judy::fromArray(Judy::STRING_TO_INT, ["apple" => 1, "banana" => 2]);
echo "STRING_TO_INT count: " . $j->count() . "\n";
var_dump($j->toArray());

// STRING_TO_MIXED
$j = Judy::fromArray(Judy::STRING_TO_MIXED, ["name" => "John", "age" => 30]);
echo "STRING_TO_MIXED count: " . $j->count() . "\n";
var_dump($j->toArray());
?>
--EXPECT--
BITSET count: 4
array(4) {
  [0]=>
  int(1)
  [1]=>
  int(5)
  [2]=>
  int(10)
  [3]=>
  int(100)
}
INT_TO_INT count: 3
array(3) {
  [0]=>
  int(100)
  [5]=>
  int(200)
  [10]=>
  int(300)
}
INT_TO_MIXED count: 2
array(2) {
  [0]=>
  string(5) "hello"
  [5]=>
  int(42)
}
STRING_TO_INT count: 2
array(2) {
  ["apple"]=>
  int(1)
  ["banana"]=>
  int(2)
}
STRING_TO_MIXED count: 2
array(2) {
  ["age"]=>
  int(30)
  ["name"]=>
  string(4) "John"
}
