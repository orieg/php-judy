--TEST--
Judy fromArray with invalid type throws exception
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
try {
    $j = Judy::fromArray(999, [1, 2, 3]);
    echo "ERROR: no exception\n";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
?>
--EXPECTF--
Warning: Judy::fromArray(): Not a valid Judy type. Please check the documentation for valid Judy type constant. in %s on line %d
Exception: Invalid Judy type
