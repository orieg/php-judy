--TEST--
Judy INT_TO_PACKED unserializable value (Closure) produces warning
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);

// Closures cannot be serialized
try {
    $j[0] = function() { return 42; };
} catch (\Throwable $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

// Verify array is still usable
$j[1] = "works";
echo "1: " . var_export($j[1], true) . "\n";

echo "Done\n";
?>
--EXPECTF--
Caught: %s
1: 'works'
Done
