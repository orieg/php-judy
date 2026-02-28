--TEST--
Judy STRING_TO_MIXED jsonSerialize() - returns key => mixed value object
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED);
$j["name"] = "John";
$j["age"] = 30;
$j["active"] = true;

$json = json_encode($j);
echo "JSON: $json\n";

$decoded = json_decode($json, true);
var_dump($decoded);
?>
--EXPECT--
JSON: {"active":true,"age":30,"name":"John"}
array(3) {
  ["active"]=>
  bool(true)
  ["age"]=>
  int(30)
  ["name"]=>
  string(4) "John"
}
