--TEST--
Judy memoryUsage() STRING_TO_INT returns null
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_INT);
for ($i = 0; $i < 100; $i++) { $j["key_$i"] = $i; }
$mem = $j->memoryUsage();
echo ($mem === null) ? "ok" : "fail";
echo "\n";
?>
--EXPECT--
ok
