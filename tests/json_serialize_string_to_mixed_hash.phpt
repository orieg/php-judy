--TEST--
Judy STRING_TO_MIXED_HASH jsonSerialize() - returns key => value object sorted alphabetically
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED_HASH);
$j["name"]   = "John";
$j["age"]    = 30;
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
