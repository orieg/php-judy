--TEST--
Judy INT_TO_PACKED scalar fast-path round-trips correctly
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);

// Tag 0: long
$j[0] = 42;
$j[1] = -99;
$j[2] = 0;
$j[3] = PHP_INT_MAX;
$j[4] = PHP_INT_MIN;

// Tag 1: double
$j[10] = 3.14;
$j[11] = -0.0;
$j[12] = INF;
$j[13] = -INF;
$j[14] = NAN;

// Tag 2/3: bool
$j[20] = true;
$j[21] = false;

// Tag 4: null
$j[30] = null;

// Tag 5: string
$j[40] = "hello";
$j[41] = "";
$j[42] = str_repeat("x", 1000);
$j[43] = "binary\x00data\x01here";

// Verify long values
var_dump($j[0] === 42);
var_dump($j[1] === -99);
var_dump($j[2] === 0);
var_dump($j[3] === PHP_INT_MAX);
var_dump($j[4] === PHP_INT_MIN);

// Verify double values
var_dump($j[10] === 3.14);
var_dump($j[11] === -0.0);
var_dump($j[12] === INF);
var_dump($j[13] === -INF);
var_dump(is_nan($j[14]));

// Verify bool values
var_dump($j[20] === true);
var_dump($j[21] === false);

// Verify null
var_dump($j[30] === null);

// Verify string values
var_dump($j[40] === "hello");
var_dump($j[41] === "");
var_dump($j[42] === str_repeat("x", 1000));
var_dump($j[43] === "binary\x00data\x01here");

// Verify foreach iteration returns correct types
$types = [];
foreach ($j as $k => $v) {
    $types[$k] = gettype($v);
}
var_dump($types[0] === "integer");
var_dump($types[10] === "double");
var_dump($types[20] === "boolean");
var_dump($types[30] === "NULL");
var_dump($types[40] === "string");

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
bool(true)
bool(true)
OK
