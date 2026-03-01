--TEST--
Judy counter correctness after serialize/unserialize roundtrip for all types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// BITSET
$j = new Judy(Judy::BITSET);
$j[5] = true;
$j[10] = true;
$j[15] = true;
$s = serialize($j);
$j2 = unserialize($s);
var_dump(count($j2));  // 3

// INT_TO_INT
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 100;
$j[1] = 200;
$s = serialize($j);
$j2 = unserialize($s);
var_dump(count($j2));  // 2
var_dump($j2[1]);      // 200

// INT_TO_MIXED
$j = new Judy(Judy::INT_TO_MIXED);
$j[0] = "hello";
$j[1] = [1,2,3];
$s = serialize($j);
$j2 = unserialize($s);
var_dump(count($j2));  // 2
var_dump($j2[0]);      // "hello"

// INT_TO_PACKED
$j = new Judy(Judy::INT_TO_PACKED);
$j[0] = 42;
$j[1] = "world";
$j[2] = 3.14;
$s = serialize($j);
$j2 = unserialize($s);
var_dump(count($j2));  // 3
var_dump($j2[2]);      // 3.14

// STRING_TO_INT
$j = new Judy(Judy::STRING_TO_INT);
$j["a"] = 1;
$j["b"] = 2;
$s = serialize($j);
$j2 = unserialize($s);
var_dump(count($j2));  // 2

// STRING_TO_MIXED
$j = new Judy(Judy::STRING_TO_MIXED);
$j["x"] = "hello";
$j["y"] = 42;
$s = serialize($j);
$j2 = unserialize($s);
var_dump(count($j2));  // 2

echo "OK\n";
?>
--EXPECT--
int(3)
int(2)
int(200)
int(2)
string(5) "hello"
int(3)
float(3.14)
int(2)
int(2)
OK
