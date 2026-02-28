--TEST--
Judy memoryUsage() populated arrays and STRING types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// INT_TO_INT with 1000 elements
$j = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < 1000; $i++) { $j[$i] = $i; }
$mem = $j->memoryUsage();
echo "INT_TO_INT 1000: " . (is_int($mem) && $mem > 0 ? "ok" : "fail") . "\n";

// BITSET with 1000 elements
$j = new Judy(Judy::BITSET);
for ($i = 0; $i < 1000; $i++) { $j[$i] = true; }
$mem = $j->memoryUsage();
echo "BITSET 1000: " . (is_int($mem) && $mem > 0 ? "ok" : "fail") . "\n";

// STRING_TO_INT: returns null
$j = new Judy(Judy::STRING_TO_INT);
for ($i = 0; $i < 100; $i++) { $j["key_$i"] = $i; }
$mem = $j->memoryUsage();
echo "STRING_TO_INT: " . ($mem === null ? "null" : "unexpected: $mem") . "\n";

// STRING_TO_MIXED: returns null
$j = new Judy(Judy::STRING_TO_MIXED);
for ($i = 0; $i < 100; $i++) { $j["key_$i"] = "value_$i"; }
$mem = $j->memoryUsage();
echo "STRING_TO_MIXED: " . ($mem === null ? "null" : "unexpected: $mem") . "\n";
?>
--EXPECT--
INT_TO_INT 1000: ok
BITSET 1000: ok
STRING_TO_INT: null
STRING_TO_MIXED: null
