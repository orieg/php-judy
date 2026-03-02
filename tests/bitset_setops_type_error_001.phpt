--TEST--
Judy set operations - type errors for unsupported and mismatched types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$bitset = new Judy(Judy::BITSET);
$bitset[1] = true;

$intToInt = new Judy(Judy::INT_TO_INT);
$intToInt[1] = 100;

// Test type mismatch: INT_TO_INT vs BITSET
try {
    $intToInt->union($bitset);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "union mismatch: " . $e->getMessage() . "\n";
}

// Test type mismatch: BITSET vs INT_TO_INT
try {
    $bitset->union($intToInt);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "union reverse mismatch: " . $e->getMessage() . "\n";
}

// Test type mismatch for intersect
try {
    $intToInt->intersect($bitset);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "intersect mismatch: " . $e->getMessage() . "\n";
}

// Test type mismatch for diff
try {
    $intToInt->diff($bitset);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "diff mismatch: " . $e->getMessage() . "\n";
}

// Test type mismatch for xor
try {
    $intToInt->xor($bitset);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "xor mismatch: " . $e->getMessage() . "\n";
}

// Test with unsupported type STRING_TO_MIXED
$strToMixed = new Judy(Judy::STRING_TO_MIXED);
$strToMixed["foo"] = "bar";

try {
    $strToMixed->union($bitset);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "union on STRING_TO_MIXED: " . $e->getMessage() . "\n";
}

// Test STRING_TO_INT intersect now succeeds (supported since Phase 3)
$strToInt = new Judy(Judy::STRING_TO_INT);
$strToInt["foo"] = 1;
$strToInt["bar"] = 2;
$strToInt2 = new Judy(Judy::STRING_TO_INT);
$strToInt2["bar"] = 20;
$strToInt2["baz"] = 30;

$result = $strToInt->intersect($strToInt2);
echo "intersect on STRING_TO_INT: count=" . count($result) . "\n";
foreach ($result as $k => $v) {
    echo "  $k => $v\n";
}
?>
--EXPECT--
union mismatch: Both Judy arrays must be the same type for set operations
union reverse mismatch: Both Judy arrays must be the same type for set operations
intersect mismatch: Both Judy arrays must be the same type for set operations
diff mismatch: Both Judy arrays must be the same type for set operations
xor mismatch: Both Judy arrays must be the same type for set operations
union on STRING_TO_MIXED: Set operations are only supported on BITSET and integer-valued arrays
intersect on STRING_TO_INT: count=1
  bar => 2
