--TEST--
Judy INT_TO_PACKED append mode ($j[] = value), count, size
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);

$j[] = "first";
$j[] = "second";
$j[] = "third";

echo "count: " . $j->count() . "\n";
echo "size: " . $j->size() . "\n";

echo "0: " . var_export($j[0], true) . "\n";
echo "1: " . var_export($j[1], true) . "\n";
echo "2: " . var_export($j[2], true) . "\n";

echo "Done\n";
?>
--EXPECT--
count: 3
size: 3
0: 'first'
1: 'second'
2: 'third'
Done
