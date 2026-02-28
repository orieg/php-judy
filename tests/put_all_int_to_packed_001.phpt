--TEST--
Judy INT_TO_PACKED putAll bulk insert
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);
$j->putAll([0 => "hello", 5 => 42, 10 => [1, 2]]);

echo "Count: " . $j->count() . "\n";
echo "0: " . var_export($j[0], true) . "\n";
echo "5: " . var_export($j[5], true) . "\n";
echo "10: " . var_export($j[10], true) . "\n";
echo "Done\n";
?>
--EXPECT--
Count: 3
0: 'hello'
5: 42
10: array (
  0 => 1,
  1 => 2,
)
Done
