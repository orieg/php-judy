--TEST--
Judy INT_TO_MIXED toArray returns index=>mixed
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_MIXED);
$j[0] = "hello";
$j[5] = 42;
$j[10] = [1, 2, 3];

$arr = $j->toArray();
var_dump($arr);
?>
--EXPECT--
array(3) {
  [0]=>
  string(5) "hello"
  [5]=>
  int(42)
  [10]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(2)
    [2]=>
    int(3)
  }
}
