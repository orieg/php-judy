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

// Test 3: Invalid type value (out of enum range; no JTYPE E_WARNING leaks)
try {
    $data = 'O:4:"Judy":2:{s:4:"type";i:99;s:4:"data";a:0:{}}';
    $j = unserialize($data);
    echo "FAIL: should have thrown\n";
} catch (\Exception $e) {
    echo "Invalid type: " . $e->getMessage() . "\n";
}

// Test 3b: Type 12 does not exist (enum ends at STRING_TO_ENTRY = 11)
try {
    $data = 'O:4:"Judy":2:{s:4:"type";i:12;s:4:"data";a:0:{}}';
    $j = unserialize($data);
    echo "FAIL: should have thrown\n";
} catch (\Exception $e) {
    echo "Invalid type 12: " . $e->getMessage() . "\n";
}

// Test 4: Valid empty roundtrip
$j = new Judy(Judy::BITSET);
$s = serialize($j);
$r = unserialize($s);
echo "Empty BITSET count: " . $r->count() . "\n";
echo "Empty BITSET type: " . $r->getType() . "\n";

// Test 5: Unknown top-level payload key is rejected
$j = new Judy(Judy::BITSET);
try {
    $j->__unserialize(['type' => Judy::BITSET, 'data' => [], 'extra' => 1]);
    echo "FAIL: should have thrown\n";
} catch (\Exception $e) {
    echo "Unknown key: " . $e->getMessage() . "\n";
}

// Test 6: Integer top-level payload key is rejected too
$j = new Judy(Judy::BITSET);
try {
    $j->__unserialize([0 => 'x']);
    echo "FAIL: should have thrown\n";
} catch (\Exception $e) {
    echo "Integer key: " . $e->getMessage() . "\n";
}

// Test 7: optimizeIteration must be a literal bool, not just truthy
$j = new Judy(Judy::STRING_TO_INT_HASH);
try {
    $j->__unserialize(['type' => Judy::STRING_TO_INT_HASH, 'data' => [], 'optimizeIteration' => 1]);
    echo "FAIL: should have thrown\n";
} catch (\Exception $e) {
    echo "Non-bool optimizeIteration: " . $e->getMessage() . "\n";
}

// Test 8: optimizeIteration=true on a type that cannot honour it is corrupt
$j = new Judy(Judy::BITSET);
try {
    $j->__unserialize(['type' => Judy::BITSET, 'data' => [], 'optimizeIteration' => true]);
    echo "FAIL: should have thrown\n";
} catch (\Exception $e) {
    echo "Unhonourable optimizeIteration: " . $e->getMessage() . "\n";
}

// Test 8b: A rejected payload must leave an already-populated object intact
$j = new Judy(Judy::STRING_TO_INT_HASH, true);
$j['k'] = 7;
try {
    $j->__unserialize(['type' => Judy::BITSET, 'data' => [], 'optimizeIteration' => true]);
    echo "FAIL: should have thrown\n";
} catch (\Exception $e) {
    echo "Reject keeps contents: " . $e->getMessage() . "\n";
}
echo "Count after rejection: " . $j->count() . " value: " . $j['k'] . "\n";

// Test 9: Honourable optimizeIteration=true roundtrips and takes effect
$j = new Judy(Judy::STRING_TO_INT_HASH, true);
$j['k'] = 7;
$r = unserialize(serialize($j));
echo "Opted roundtrip: " . var_export($r->isIterationOptimized(), true) . " " . $r['k'] . "\n";

// Test 10: optimizeIteration=false on a non-honouring type is the default and fine
$j = new Judy(Judy::BITSET);
$j->__unserialize(['type' => Judy::BITSET, 'data' => [], 'optimizeIteration' => false]);
echo "Explicit off BITSET count: " . $j->count() . "\n";
?>
--EXPECTF--
Missing type: Invalid serialization data for Judy array
Missing data: Invalid serialization data for Judy array
Invalid type: Invalid Judy type in serialized data
Invalid type 12: Invalid Judy type in serialized data
Empty BITSET count: 0
Empty BITSET type: 1
Unknown key: Invalid serialization data for Judy array
Integer key: Invalid serialization data for Judy array
Non-bool optimizeIteration: Invalid serialization data for Judy array
Unhonourable optimizeIteration: Invalid Judy type in serialized data
Reject keeps contents: Invalid Judy type in serialized data
Count after rejection: 1 value: 7
Opted roundtrip: true 7
Explicit off BITSET count: 0