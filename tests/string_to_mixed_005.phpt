--TEST--
Check for Judy STRING_TO_MIXED works with $a[] = $b (expect error)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_MIXED);
try {
    $judy[] = 1;
} catch (\Exception $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECT--
Caught: Judy STRING_TO_INT and STRING_TO_MIXED values cannot be set without specifying a key
Done
