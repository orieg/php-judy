--TEST--
Judy memoryUsage() basic test for INT_TO_INT
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < 10; $i++) {
    $j[$i] = $i;
}
$mem = $j->memoryUsage();
echo (is_int($mem) && $mem > 0) ? "ok" : "fail";
echo "\n";
?>
--EXPECT--
ok
