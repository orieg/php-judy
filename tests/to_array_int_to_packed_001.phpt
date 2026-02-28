--TEST--
Judy INT_TO_PACKED toArray roundtrip
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);
$j[0] = "hello";
$j[5] = 42;
$j[10] = [1, 2, 3];

$arr = $j->toArray();
echo var_export($arr, true) . "\n";
echo "Done\n";
?>
--EXPECT--
array (
  0 => 'hello',
  5 => 42,
  10 => array (
    0 => 1,
    1 => 2,
    2 => 3,
  ),
)
Done
