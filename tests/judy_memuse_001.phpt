--TEST--
Judy memoryUsage() on empty arrays (NULL intern->array)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Test memoryUsage() on freshly created (empty) arrays where intern->array is NULL
$j = new Judy(Judy::INT_TO_INT);
$mem = $j->memoryUsage();
echo "INT_TO_INT empty: " . (is_int($mem) ? "ok" : "fail") . "\n";

$j = new Judy(Judy::BITSET);
$mem = $j->memoryUsage();
echo "BITSET empty: " . (is_int($mem) ? "ok" : "fail") . "\n";
?>
--EXPECT--
INT_TO_INT empty: ok
BITSET empty: ok
