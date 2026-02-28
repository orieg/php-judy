--TEST--
Judy memoryUsage() STRING_TO_MIXED_HASH returns null
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED_HASH);
for ($i = 0; $i < 100; $i++) { $j["key_$i"] = "value_$i"; }
$mem = $j->memoryUsage();
echo ($mem === null) ? "ok" : "fail";
echo "\n";
?>
--EXPECT--
ok
