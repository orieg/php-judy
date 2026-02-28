--TEST--
Judy INT_TO_PACKED toArray roundtrip
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);
$j[0] = "hello";
$j[5] = 42;
$j[10] = [1, 2, 3];

$arr = $j->toArray();
var_dump($arr);
echo "Done\n";
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
Done
