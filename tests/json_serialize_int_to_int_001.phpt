--TEST--
Judy INT_TO_INT jsonSerialize() - returns index => value object
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 100;
$j[5] = 200;
$j[10] = 300;

$json = json_encode($j);
echo "JSON: $json\n";

$decoded = json_decode($json, true);
var_dump($decoded);

// Empty
$empty = new Judy(Judy::INT_TO_INT);
echo "Empty: " . json_encode($empty) . "\n";
?>
--EXPECT--
JSON: {"0":100,"5":200,"10":300}
array(3) {
  [0]=>
  int(100)
  [5]=>
  int(200)
  [10]=>
  int(300)
}
Empty: []
