--TEST--
Judy INT_TO_PACKED increment() throws exception
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);
$j[0] = 42;

try {
    $j->increment(0);
    echo "ERROR: should have thrown\n";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
echo "Done\n";
?>
--EXPECT--
Exception: Judy::increment() is only supported for INT_TO_INT, STRING_TO_INT and STRING_TO_INT_HASH types
Done
