--TEST--
Judy keys() and values() for all types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// INT_TO_INT
$j = new Judy(Judy::INT_TO_INT);
$j[10] = 100;
$j[20] = 200;
$j[30] = 300;
echo "INT_TO_INT keys: ";
var_dump($j->keys());
echo "INT_TO_INT values: ";
var_dump($j->values());

// STRING_TO_INT
$j2 = new Judy(Judy::STRING_TO_INT);
$j2["alpha"] = 1;
$j2["beta"] = 2;
echo "STRING_TO_INT keys: ";
var_dump($j2->keys());
echo "STRING_TO_INT values: ";
var_dump($j2->values());

// BITSET
$j3 = new Judy(Judy::BITSET);
$j3[5] = true;
$j3[10] = true;
$j3[15] = true;
echo "BITSET keys: ";
var_dump($j3->keys());
echo "BITSET values: ";
var_dump($j3->values());

// Empty array
$j4 = new Judy(Judy::INT_TO_INT);
echo "Empty keys: ";
var_dump($j4->keys());
echo "Empty values: ";
var_dump($j4->values());
?>
--EXPECT--
INT_TO_INT keys: array(3) {
  [0]=>
  int(10)
  [1]=>
  int(20)
  [2]=>
  int(30)
}
INT_TO_INT values: array(3) {
  [0]=>
  int(100)
  [1]=>
  int(200)
  [2]=>
  int(300)
}
STRING_TO_INT keys: array(2) {
  [0]=>
  string(5) "alpha"
  [1]=>
  string(4) "beta"
}
STRING_TO_INT values: array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
BITSET keys: array(3) {
  [0]=>
  int(5)
  [1]=>
  int(10)
  [2]=>
  int(15)
}
BITSET values: array(3) {
  [0]=>
  int(5)
  [1]=>
  int(10)
  [2]=>
  int(15)
}
Empty keys: array(0) {
}
Empty values: array(0) {
}

