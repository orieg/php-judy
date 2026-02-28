--TEST--
Judy memoryUsage() returns int and increases with data
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
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
?>
--EXPECT--
Empty INT_TO_INT memoryUsage is int: yes
INT_TO_INT memoryUsage increased: yes
Empty BITSET memoryUsage is int: yes
BITSET memoryUsage increased: yes
