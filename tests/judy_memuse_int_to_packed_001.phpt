--TEST--
Judy INT_TO_PACKED memoryUsage() returns int > 0
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);

// Empty array
$mem_empty = $j->memoryUsage();
echo "empty is int: " . var_export(is_int($mem_empty), true) . "\n";

// Add some items
for ($i = 0; $i < 100; $i++) {
    $j[$i] = "value_$i";
}

$mem_full = $j->memoryUsage();
echo "full is int: " . var_export(is_int($mem_full), true) . "\n";
echo "full > 0: " . var_export($mem_full > 0, true) . "\n";
echo "full > empty: " . var_export($mem_full > $mem_empty, true) . "\n";

echo "Done\n";
?>
--EXPECT--
empty is int: true
full is int: true
full > 0: true
full > empty: true
Done
