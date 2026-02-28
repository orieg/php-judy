--TEST--
Judy INT_TO_INT toArray returns index=>value
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 100;
$j[5] = 200;
$j[10] = 300;

$arr = $j->toArray();
var_dump($arr);
?>
--EXPECT--
array(3) {
  [0]=>
  int(100)
  [5]=>
  int(200)
  [10]=>
  int(300)
}
