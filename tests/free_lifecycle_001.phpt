--TEST--
Judy free() resets count/size to 0, array becomes empty
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
// INT_TO_INT
$j = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < 10; $i++) { $j[$i] = $i * 100; }
echo "Before free - count: " . $j->count() . ", size: " . $j->size() . "\n";
$bytes = $j->free();
echo "Bytes freed > 0: " . ($bytes > 0 ? "yes" : "no") . "\n";
echo "After free - count: " . $j->count() . ", size: " . $j->size() . "\n";
echo "Key 0 exists: " . (isset($j[0]) ? "yes" : "no") . "\n";

// STRING_TO_INT
$j = new Judy(Judy::STRING_TO_INT);
$j["a"] = 1; $j["b"] = 2; $j["c"] = 3;
echo "Before free - count: " . $j->count() . "\n";
$bytes = $j->free();
echo "Bytes freed > 0: " . ($bytes > 0 ? "yes" : "no") . "\n";
echo "After free - count: " . $j->count() . "\n";
echo "Key 'a' exists: " . (isset($j["a"]) ? "yes" : "no") . "\n";

// BITSET
$j = new Judy(Judy::BITSET);
$j[10] = true; $j[20] = true;
echo "Before free - count: " . $j->count() . "\n";
$bytes = $j->free();
echo "Bytes freed > 0: " . ($bytes > 0 ? "yes" : "no") . "\n";
echo "After free - count: " . $j->count() . "\n";

// INT_TO_MIXED
$j = new Judy(Judy::INT_TO_MIXED);
$j[0] = "hello"; $j[1] = [1, 2];
echo "Before free - count: " . $j->count() . "\n";
$bytes = $j->free();
echo "Bytes freed > 0: " . ($bytes > 0 ? "yes" : "no") . "\n";
echo "After free - count: " . $j->count() . "\n";

// Free on already-free array
$bytes = $j->free();
echo "Double free returns: $bytes\n";
?>
--EXPECT--
Before free - count: 10, size: 10
Bytes freed > 0: yes
After free - count: 0, size: 0
Key 0 exists: no
Before free - count: 3
Bytes freed > 0: yes
After free - count: 0
Key 'a' exists: no
Before free - count: 2
Bytes freed > 0: yes
After free - count: 0
Before free - count: 2
Bytes freed > 0: yes
After free - count: 0
Double free returns: 0
