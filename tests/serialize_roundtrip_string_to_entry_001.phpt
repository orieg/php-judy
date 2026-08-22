--TEST--
Judy STRING_TO_ENTRY PHP serialize/unserialize roundtrip
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_ENTRY);
$j["alpha"] = "hello";
$j["beta"] = 42;
$j["gamma"] = ["nested" => "data"];

$serialized = serialize($j);
$unserialized = unserialize($serialized);

var_dump($unserialized->getType() === Judy::STRING_TO_ENTRY);
var_dump(count($unserialized));
var_dump($unserialized["alpha"]);
var_dump($unserialized["beta"]);
var_dump($unserialized["gamma"]);
?>
--EXPECT--
bool(true)
int(3)
string(5) "hello"
int(42)
array(1) {
  ["nested"]=>
  string(4) "data"
}
