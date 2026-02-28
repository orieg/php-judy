--TEST--
Check for Judy STRING_TO_INT_HASH size/count methods
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_INT_HASH);

echo "Insert 50 entries\n";
for ($i = 0; $i < 50; $i++) {
    $judy["key_$i"] = $i;
}

echo "Size Method: "   . $judy->size()    . "\n";
echo "Count Method: "  . $judy->count()   . "\n";
echo "Count Function: ". count($judy)     . "\n";

// Overwrite existing key — count must stay the same
$judy["key_0"] = 999;
echo "After overwrite Size: "  . $judy->size()  . "\n";
echo "After overwrite Count: " . $judy->count() . "\n";

// Delete one key
unset($judy["key_25"]);
echo "After unset Size: "  . $judy->size()  . "\n";
echo "After unset Count: " . $judy->count() . "\n";

// Free
$judy->free();
echo "After free Size: "  . $judy->size()  . "\n";
echo "After free Count: " . $judy->count() . "\n";

echo "Done\n";
?>
--EXPECT--
Insert 50 entries
Size Method: 50
Count Method: 50
Count Function: 50
After overwrite Size: 50
After overwrite Count: 50
After unset Size: 49
After unset Count: 49
After free Size: 0
After free Count: 0
Done
