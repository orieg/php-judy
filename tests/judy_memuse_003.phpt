--TEST--
Judy memoryUsage() BITSET with 1000 elements
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::BITSET);
for ($i = 0; $i < 1000; $i++) { $j[$i] = true; }
$mem = $j->memoryUsage();
echo (is_int($mem) && $mem > 0) ? "ok" : "fail";
echo "\n";
?>
--EXPECT--
ok
