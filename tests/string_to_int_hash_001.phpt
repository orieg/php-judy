--TEST--
Check for Judy STRING_TO_INT_HASH set/unset/get/isset methods
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_INT_HASH);

// Insert

echo "Insert 100 entries with integer values\n";
for ($i = 0; $i < 100; $i++) {
    $judy["key_$i"] = $i * 10;
    if (!isset($judy["key_$i"]))
        echo "Failed to insert key_$i\n";
}

// Get

echo "Get 100 entries\n";
for ($i = 0; $i < 100; $i++) {
    $v = $judy["key_$i"];
    if ($v !== $i * 10)
        echo "Get key_$i returned $v, expected " . ($i * 10) . "\n";
}

// Remove

echo "Remove 100 entries\n";
for ($i = 0; $i < 100; $i++) {
    unset($judy["key_$i"]);
    if (isset($judy["key_$i"]))
        echo "Failed to remove key_$i\n";
}

// Get (after removal)

echo "Get 100 entries (should be empty)\n";
for ($i = 0; $i < 100; $i++) {
    if (($v = $judy["key_$i"]) !== null)
        echo "Get key_$i returned $v\n";
}

echo "Testing value round-trip\n";
$judy["hello"] = 42;
$v = $judy["hello"];
if ($v !== 42)
    echo "Value mismatch: expected 42 got $v\n";

echo "Testing zero value storage\n";
$judy["zero"] = 0;
var_dump($judy["zero"]);
var_dump(isset($judy["zero"]));

echo "Done\n";
?>
--EXPECT--
Insert 100 entries with integer values
Get 100 entries
Remove 100 entries
Get 100 entries (should be empty)
Testing value round-trip
Testing zero value storage
int(0)
bool(true)
Done
