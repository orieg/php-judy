--TEST--
Judy BITSET toArray returns flat index array
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::BITSET);
$j[1] = true;
$j[5] = true;
$j[10] = true;
$j[100] = true;

$arr = $j->toArray();
var_dump($arr);
?>
--EXPECT--
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
