--TEST--
Judy putAll bulk insert for all 5 types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// BITSET
$j = new Judy(Judy::BITSET);
$j->putAll([1, 5, 10]);
echo "BITSET count: " . $j->count() . "\n";
var_dump($j->toArray());

// INT_TO_INT
$j = new Judy(Judy::INT_TO_INT);
$j->putAll([0 => 100, 5 => 200, 10 => 300]);
echo "INT_TO_INT count: " . $j->count() . "\n";
var_dump($j->toArray());

// INT_TO_MIXED
$j = new Judy(Judy::INT_TO_MIXED);
$j->putAll([0 => "hello", 5 => 42]);
echo "INT_TO_MIXED count: " . $j->count() . "\n";
var_dump($j->toArray());

// STRING_TO_INT
$j = new Judy(Judy::STRING_TO_INT);
$j->putAll(["apple" => 1, "banana" => 2]);
echo "STRING_TO_INT count: " . $j->count() . "\n";
var_dump($j->toArray());

// STRING_TO_MIXED
$j = new Judy(Judy::STRING_TO_MIXED);
$j->putAll(["name" => "John", "age" => 30]);
echo "STRING_TO_MIXED count: " . $j->count() . "\n";
var_dump($j->toArray());
?>
--EXPECT--
BITSET count: 3
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(5)
  [2]=>
  int(10)
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
