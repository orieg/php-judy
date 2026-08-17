--TEST--
Judy memoryUsage() STRING_TO_INT returns approximate bytes
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_INT);
echo "empty: ", var_export($j->memoryUsage(), true), "\n";
for ($i = 0; $i < 100; $i++) { $j["key_$i"] = $i; }
$mem = $j->memoryUsage();
echo "populated is int: ", (is_int($mem) ? "yes" : "no"), "\n";
echo "populated > 0: ", ($mem > 0 ? "yes" : "no"), "\n";
/* The figure is approximate but not arbitrary: it counts at least the key
   bytes it stores. */
$key_bytes = 0;
for ($i = 0; $i < 100; $i++) { $key_bytes += strlen("key_$i"); }
echo "covers key bytes: ", ($mem > $key_bytes ? "yes" : "no"), "\n";
$j->free();
echo "after free: ", var_export($j->memoryUsage(), true), "\n";
?>
--EXPECT--
empty: 0
populated is int: yes
populated > 0: yes
covers key bytes: yes
after free: 0
