--TEST--
Judy STRING_TO_INT jsonSerialize() - returns key => value object
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_INT);
$j["apple"] = 1;
$j["banana"] = 2;
$j["cherry"] = 3;

$json = json_encode($j);
echo "JSON: $json\n";

$decoded = json_decode($json, true);
var_dump($decoded);
?>
--EXPECT--
JSON: {"apple":1,"banana":2,"cherry":3}
array(3) {
  ["apple"]=>
  int(1)
  ["banana"]=>
  int(2)
  ["cherry"]=>
  int(3)
}
