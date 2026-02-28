--TEST--
Check for Judy STRING_TO_MIXED_HASH set/unset/get/isset methods
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_MIXED_HASH);

// Insert

echo "Insert 100 entries with a rand value\n";
for ($i = 0; $i < 100; $i++) {
    $value = rand();
    $judy["key_$i"] = "$value";
    if (!isset($judy["key_$i"]))
        echo "Failed to insert key_$i (value: $value)\n";
}

// Get

echo "Get 100 entries\n";
for ($i = 0; $i < 100; $i++) {
    if ($judy["key_$i"] === null)
        echo "Get key_$i returned null\n";
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
$judy["hello"] = "world";
$v = $judy["hello"];
if ($v !== "world")
    echo "Value mismatch: expected 'world' got '$v'\n";

echo "Done\n";
?>
--EXPECT--
Insert 100 entries with a rand value
Get 100 entries
Remove 100 entries
Get 100 entries (should be empty)
Testing value round-trip
Done
