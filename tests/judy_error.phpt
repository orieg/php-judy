--TEST--
Check for Error with Judy::__construct() when object has been already instantiated
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$judy = new Judy(Judy::BITSET);
try {
    $judy->__construct(Judy::INT_TO_INT);
} catch (Exception $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}
?>
--EXPECT--
Caught: Judy Array already instantiated
