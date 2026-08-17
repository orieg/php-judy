--TEST--
Judy memoryUsage() STRING_TO_MIXED_HASH returns approximate bytes (keys held twice)
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED_HASH);
echo "empty: ", var_export($j->memoryUsage(), true), "\n";
for ($i = 0; $i < 100; $i++) { $j["key_$i"] = "value_$i"; }
$mem = $j->memoryUsage();
echo "populated is int: ", (is_int($mem) ? "yes" : "no"), "\n";

/* A _HASH type keeps every key twice — once in the JudyHS value store, once in
   the JudySL key index that makes ordered iteration possible — so it accounts
   for more than the single-copy trie type holding the same entries. */
$trie = new Judy(Judy::STRING_TO_MIXED);
for ($i = 0; $i < 100; $i++) { $trie["key_$i"] = "value_$i"; }
echo "exceeds single-copy trie: ", ($mem > $trie->memoryUsage() ? "yes" : "no"), "\n";

$j->free();
echo "after free: ", var_export($j->memoryUsage(), true), "\n";
?>
--EXPECT--
empty: 0
populated is int: yes
exceeds single-copy trie: yes
after free: 0
