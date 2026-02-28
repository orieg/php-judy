--TEST--
Judy INT_TO_MIXED jsonSerialize() - returns index => mixed value object
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_MIXED);
$j[0] = "hello";
$j[5] = 42;
$j[10] = true;
$j[15] = null;

$json = json_encode($j);
echo "JSON: $json\n";

$decoded = json_decode($json, true);
var_dump($decoded);
?>
--EXPECT--
JSON: {"0":"hello","5":42,"10":true,"15":null}
array(4) {
  [0]=>
  string(5) "hello"
  [5]=>
  int(42)
  [10]=>
  bool(true)
  [15]=>
  NULL
}
