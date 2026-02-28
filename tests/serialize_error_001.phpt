--TEST--
Judy __unserialize() - error handling for invalid data
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Test 1: Missing 'type' key
try {
    $data = 'O:4:"Judy":1:{s:4:"data";a:0:{}}';
    $j = unserialize($data);
    echo "FAIL: should have thrown\n";
} catch (\Exception $e) {
    echo "Missing type: " . $e->getMessage() . "\n";
}

// Test 2: Missing 'data' key
try {
    $data = 'O:4:"Judy":1:{s:4:"type";i:1;}';
    $j = unserialize($data);
    echo "FAIL: should have thrown\n";
} catch (\Exception $e) {
    echo "Missing data: " . $e->getMessage() . "\n";
}

// Test 3: Invalid type value
try {
    $data = 'O:4:"Judy":2:{s:4:"type";i:99;s:4:"data";a:0:{}}';
    $j = unserialize($data);
    echo "FAIL: should have thrown\n";
} catch (\Exception $e) {
    echo "Invalid type: " . $e->getMessage() . "\n";
}

// Test 4: Valid empty roundtrip
$j = new Judy(Judy::BITSET);
$s = serialize($j);
$r = unserialize($s);
echo "Empty BITSET count: " . $r->count() . "\n";
echo "Empty BITSET type: " . $r->getType() . "\n";
?>
--EXPECTF--
Missing type: Invalid serialization data for Judy array
Missing data: Invalid serialization data for Judy array
%AInvalid type: Invalid Judy type in serialized data
Empty BITSET count: 0
Empty BITSET type: 1
