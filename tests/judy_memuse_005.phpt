--TEST--
Judy memoryUsage() STRING_TO_MIXED returns approximate bytes
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::STRING_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED);
echo "empty: ", var_export($j->memoryUsage(), true), "\n";
for ($i = 0; $i < 100; $i++) { $j["key_$i"] = "value_$i"; }
$mem = $j->memoryUsage();
echo "populated is int: ", (is_int($mem) ? "yes" : "no"), "\n";
echo "populated > 0: ", ($mem > 0 ? "yes" : "no"), "\n";
/* MIXED values carry a zval box each, so the same keys cost more here than in
   the STRING_TO_INT twin. */
$i2i = new Judy(Judy::STRING_TO_INT);
for ($i = 0; $i < 100; $i++) { $i2i["key_$i"] = $i; }
echo "costs more than STRING_TO_INT: ", ($mem > $i2i->memoryUsage() ? "yes" : "no"), "\n";
$j->free();
echo "after free: ", var_export($j->memoryUsage(), true), "\n";
?>
--EXPECT--
empty: 0
populated is int: yes
populated > 0: yes
costs more than STRING_TO_INT: yes
after free: 0
