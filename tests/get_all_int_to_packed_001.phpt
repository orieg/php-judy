--TEST--
Judy INT_TO_PACKED getAll bulk retrieval
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);
$j[0] = "zero";
$j[5] = 42;
$j[10] = [1, 2];

$result = $j->getAll([0, 5, 10, 99]);

var_dump($result);
echo "Done\n";
?>
--EXPECT--
array(4) {
  [0]=>
  string(4) "zero"
  [5]=>
  int(42)
  [10]=>
  array(2) {
    [0]=>
    int(1)
    [1]=>
    int(2)
  }
  [99]=>
  NULL
}
Done
