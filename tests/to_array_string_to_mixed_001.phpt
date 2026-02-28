--TEST--
Judy STRING_TO_MIXED toArray returns key=>mixed
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED);
$j["name"] = "John";
$j["age"] = 30;
$j["scores"] = [85, 92];

$arr = $j->toArray();
var_dump($arr);
?>
--EXPECT--
array(3) {
  ["age"]=>
  int(30)
  ["name"]=>
  string(4) "John"
  ["scores"]=>
  array(2) {
    [0]=>
    int(85)
    [1]=>
    int(92)
  }
}
