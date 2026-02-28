--TEST--
Judy putAll overwrites existing keys
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// INT_TO_INT: overwrite existing
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 100;
$j[5] = 200;
$j->putAll([0 => 999, 5 => 888, 10 => 777]);
echo "INT_TO_INT count: " . $j->count() . "\n";
var_dump($j->toArray());

// STRING_TO_INT: overwrite existing
$j = new Judy(Judy::STRING_TO_INT);
$j["apple"] = 1;
$j["banana"] = 2;
$j->putAll(["apple" => 99, "cherry" => 3]);
echo "STRING_TO_INT count: " . $j->count() . "\n";
var_dump($j->toArray());

// STRING_TO_MIXED: overwrite existing
$j = new Judy(Judy::STRING_TO_MIXED);
$j["name"] = "John";
$j->putAll(["name" => "Jane", "age" => 25]);
echo "STRING_TO_MIXED count: " . $j->count() . "\n";
var_dump($j->toArray());
?>
--EXPECT--
INT_TO_INT count: 3
array(3) {
  [0]=>
  int(999)
  [5]=>
  int(888)
  [10]=>
  int(777)
}
STRING_TO_INT count: 3
array(3) {
  ["apple"]=>
  int(99)
  ["banana"]=>
  int(2)
  ["cherry"]=>
  int(3)
}
STRING_TO_MIXED count: 2
array(2) {
  ["age"]=>
  int(25)
  ["name"]=>
  string(4) "Jane"
}
