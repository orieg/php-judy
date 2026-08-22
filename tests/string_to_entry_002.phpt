--TEST--
Judy::STRING_TO_ENTRY ArrayAccess and Countable support
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_ENTRY);
$j["k1"] = "val1";
$j["k2"] = 12345;
$j["k3"] = ["nested" => true];

var_dump(count($j));
var_dump(isset($j["k1"]));
var_dump(isset($j["k2"]));
var_dump(isset($j["k4"]));
var_dump($j["k1"]);
var_dump($j["k2"]);
var_dump($j["k3"]);

unset($j["k2"]);
var_dump(count($j));
var_dump(isset($j["k2"]));
var_dump($j["k2"]);
?>
--EXPECT--
int(3)
bool(true)
bool(true)
bool(false)
string(4) "val1"
int(12345)
array(1) {
  ["nested"]=>
  bool(true)
}
int(2)
bool(false)
NULL
