--TEST--
Judy INT_TO_PACKED complex types fall back to serialize correctly
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);

// Arrays
$j[0] = [1, 2, 3];
$j[1] = ["key" => "value", "nested" => [1, 2]];
$j[2] = [];

// Objects
$obj = new stdClass();
$obj->name = "test";
$obj->value = 42;
$j[3] = $obj;

// Verify arrays
var_dump($j[0] === [1, 2, 3]);
var_dump($j[1] === ["key" => "value", "nested" => [1, 2]]);
var_dump($j[2] === []);

// Verify object
$restored = $j[3];
var_dump($restored instanceof stdClass);
var_dump($restored->name === "test");
var_dump($restored->value === 42);

// Verify overwrite works
$j[0] = [4, 5];
var_dump($j[0] === [4, 5]);

// Mixed types in same array
$j[10] = 42;         // scalar fast-path
$j[11] = [1, 2, 3];  // serialize fallback
$j[12] = "hello";    // scalar fast-path
var_dump($j[10] === 42);
var_dump($j[11] === [1, 2, 3]);
var_dump($j[12] === "hello");

echo "OK\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
OK
