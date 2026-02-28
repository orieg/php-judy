--TEST--
Judy STRING_TO_INT toArray returns key=>value
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_INT);
$j["apple"] = 1;
$j["banana"] = 2;
$j["cherry"] = 3;

$arr = $j->toArray();
var_dump($arr);
?>
--EXPECT--
array(3) {
  ["apple"]=>
  int(1)
  ["banana"]=>
  int(2)
  ["cherry"]=>
  int(3)
}
