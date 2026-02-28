--TEST--
Judy getAll bulk get for all 5 types including missing keys
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// BITSET
$j = new Judy(Judy::BITSET);
$j[1] = true;
$j[5] = true;
$j[10] = true;
$result = $j->getAll([1, 5, 99]);
echo "BITSET:\n";
var_dump($result);

// INT_TO_INT
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 100;
$j[5] = 200;
$result = $j->getAll([0, 5, 99]);
echo "INT_TO_INT:\n";
var_dump($result);

// INT_TO_MIXED
$j = new Judy(Judy::INT_TO_MIXED);
$j[0] = "hello";
$j[5] = 42;
$result = $j->getAll([0, 5, 99]);
echo "INT_TO_MIXED:\n";
var_dump($result);

// STRING_TO_INT
$j = new Judy(Judy::STRING_TO_INT);
$j["apple"] = 1;
$j["banana"] = 2;
$result = $j->getAll(["apple", "banana", "missing"]);
echo "STRING_TO_INT:\n";
var_dump($result);

// STRING_TO_MIXED
$j = new Judy(Judy::STRING_TO_MIXED);
$j["name"] = "John";
$j["age"] = 30;
$result = $j->getAll(["name", "age", "missing"]);
echo "STRING_TO_MIXED:\n";
var_dump($result);
?>
--EXPECT--
BITSET:
array(3) {
  [1]=>
  bool(true)
  [5]=>
  bool(true)
  [99]=>
  bool(false)
}
INT_TO_INT:
array(3) {
  [0]=>
  int(100)
  [5]=>
  int(200)
  [99]=>
  NULL
}
INT_TO_MIXED:
array(3) {
  [0]=>
  string(5) "hello"
  [5]=>
  int(42)
  [99]=>
  NULL
}
STRING_TO_INT:
array(3) {
  ["apple"]=>
  int(1)
  ["banana"]=>
  int(2)
  ["missing"]=>
  NULL
}
STRING_TO_MIXED:
array(3) {
  ["name"]=>
  string(4) "John"
  ["age"]=>
  int(30)
  ["missing"]=>
  NULL
}
