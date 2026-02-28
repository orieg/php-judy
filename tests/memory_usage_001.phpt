--TEST--
Judy memoryUsage() returns int and increases with data for all types
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
// INT_TO_INT: memoryUsage should be integer and increase with data
$j = new Judy(Judy::INT_TO_INT);
$empty_mem = $j->memoryUsage();
echo "Empty INT_TO_INT memoryUsage is int: " . (is_int($empty_mem) ? "yes" : "no") . "\n";

for ($i = 0; $i < 1000; $i++) {
    $j[$i] = $i;
}
$full_mem = $j->memoryUsage();
echo "INT_TO_INT memoryUsage increased: " . ($full_mem > $empty_mem ? "yes" : "no") . "\n";

// BITSET: memoryUsage should work similarly
$j = new Judy(Judy::BITSET);
$empty_mem = $j->memoryUsage();
echo "Empty BITSET memoryUsage is int: " . (is_int($empty_mem) ? "yes" : "no") . "\n";

for ($i = 0; $i < 1000; $i++) {
    $j[$i] = true;
}
$full_mem = $j->memoryUsage();
echo "BITSET memoryUsage increased: " . ($full_mem > $empty_mem ? "yes" : "no") . "\n";

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
Empty INT_TO_INT memoryUsage is int: yes
INT_TO_INT memoryUsage increased: yes
Empty BITSET memoryUsage is int: yes
BITSET memoryUsage increased: yes
INT_TO_MIXED: int > 0
STRING_TO_INT: null
STRING_TO_MIXED: null
