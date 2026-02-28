--TEST--
Judy INT_TO_PACKED basic CRUD: set/get/unset/isset with various types
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);

// Insert various types
$j[0] = 42;
$j[1] = 3.14;
$j[2] = "hello";
$j[3] = [1, 2, 3];
$j[4] = null;
$j[5] = true;
$j[6] = false;

// Read back and verify types
echo "int: " . var_export($j[0], true) . "\n";
echo "float: " . var_export($j[1], true) . "\n";
echo "string: " . var_export($j[2], true) . "\n";
echo "array: " . var_export($j[3], true) . "\n";
echo "null: " . var_export($j[4], true) . "\n";
echo "true: " . var_export($j[5], true) . "\n";
echo "false: " . var_export($j[6], true) . "\n";

// isset
echo "isset(0): " . var_export(isset($j[0]), true) . "\n";
echo "isset(99): " . var_export(isset($j[99]), true) . "\n";

// unset
unset($j[2]);
echo "after unset(2): " . var_export($j[2], true) . "\n";
echo "isset(2): " . var_export(isset($j[2]), true) . "\n";

// count
echo "count: " . $j->count() . "\n";

echo "Done\n";
?>
--EXPECT--
int: 42
float: 3.14
string: 'hello'
array: array (
  0 => 1,
  1 => 2,
  2 => 3,
)
null: NULL
true: true
false: false
isset(0): true
isset(99): false
after unset(2): NULL
isset(2): false
count: 6
Done
