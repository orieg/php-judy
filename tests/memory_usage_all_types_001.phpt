--TEST--
Judy memoryUsage() for all 5 types
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
// BITSET: returns int
$j = new Judy(Judy::BITSET);
for ($i = 0; $i < 100; $i++) { $j[$i] = true; }
$mem = $j->memoryUsage();
echo "BITSET: " . (is_int($mem) && $mem > 0 ? "int > 0" : "unexpected: $mem") . "\n";

// INT_TO_INT: returns int
$j = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < 100; $i++) { $j[$i] = $i; }
$mem = $j->memoryUsage();
echo "INT_TO_INT: " . (is_int($mem) && $mem > 0 ? "int > 0" : "unexpected: $mem") . "\n";

// INT_TO_MIXED: returns int (uses JudyL internally)
$j = new Judy(Judy::INT_TO_MIXED);
for ($i = 0; $i < 100; $i++) { $j[$i] = "value_$i"; }
$mem = $j->memoryUsage();
echo "INT_TO_MIXED: " . (is_int($mem) && $mem > 0 ? "int > 0" : "unexpected: $mem") . "\n";

// STRING_TO_INT: returns null (JudySL has no memory usage function)
$j = new Judy(Judy::STRING_TO_INT);
for ($i = 0; $i < 100; $i++) { $j["key_$i"] = $i; }
$mem = $j->memoryUsage();
echo "STRING_TO_INT: " . ($mem === null ? "null" : "unexpected: $mem") . "\n";

// STRING_TO_MIXED: returns null (JudySL has no memory usage function)
$j = new Judy(Judy::STRING_TO_MIXED);
for ($i = 0; $i < 100; $i++) { $j["key_$i"] = "value_$i"; }
$mem = $j->memoryUsage();
echo "STRING_TO_MIXED: " . ($mem === null ? "null" : "unexpected: $mem") . "\n";
?>
--EXPECT--
BITSET: int > 0
INT_TO_INT: int > 0
INT_TO_MIXED: int > 0
STRING_TO_INT: null
STRING_TO_MIXED: null
