--TEST--
Judy memoryUsage() STRING_TO_MIXED returns null
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED);
for ($i = 0; $i < 3; $i++) { $j["key_$i"] = "value_$i"; }
$mem = $j->memoryUsage();
echo ($mem === null) ? "ok" : "fail";
echo "\n";
?>
--EXPECT--
ok
