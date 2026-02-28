--TEST--
Check for Judy STRING_TO_MIXED_HASH mixed value types (string/int/array/bool/null)
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_MIXED_HASH);

// String value
$judy["str"] = "hello";
var_dump($judy["str"]);

// Integer value
$judy["num"] = 42;
var_dump($judy["num"]);

// Float value
$judy["pi"] = 3.14;
var_dump($judy["pi"]);

// Bool value
$judy["flag"] = true;
var_dump($judy["flag"]);

// Array value
$judy["arr"] = [1, 2, 3];
var_dump($judy["arr"]);

// Null value
$judy["nil"] = null;
var_dump($judy["nil"]);

// Overwrite existing key
$judy["str"] = "world";
var_dump($judy["str"]);

echo "Done\n";
?>
--EXPECT--
string(5) "hello"
int(42)
float(3.14)
bool(true)
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
NULL
string(5) "world"
Done
