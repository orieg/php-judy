--TEST--
Judy INT_TO_PACKED json_encode output
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);
$j[0] = "hello";
$j[1] = 42;
$j[2] = true;
$j[3] = [1, 2];

echo json_encode($j) . "\n";
echo "Done\n";
?>
--EXPECT--
{"0":"hello","1":42,"2":true,"3":[1,2]}
Done
