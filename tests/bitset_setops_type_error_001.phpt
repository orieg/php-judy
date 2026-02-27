--TEST--
Judy BITSET set operations - type error on non-BITSET
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$bitset = new Judy(Judy::BITSET);
$bitset[1] = true;

$intToInt = new Judy(Judy::INT_TO_INT);
$intToInt[1] = 100;

// Test union on non-BITSET self
try {
    $intToInt->union($bitset);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "union on non-BITSET: " . $e->getMessage() . "\n";
}

// Test union with non-BITSET other
try {
    $bitset->union($intToInt);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "union with non-BITSET: " . $e->getMessage() . "\n";
}

// Test intersect on non-BITSET
try {
    $intToInt->intersect($bitset);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "intersect on non-BITSET: " . $e->getMessage() . "\n";
}

// Test diff on non-BITSET
try {
    $intToInt->diff($bitset);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "diff on non-BITSET: " . $e->getMessage() . "\n";
}

// Test xor on non-BITSET
try {
    $intToInt->xor($bitset);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "xor on non-BITSET: " . $e->getMessage() . "\n";
}

// Test with STRING_TO_MIXED
$strToMixed = new Judy(Judy::STRING_TO_MIXED);
$strToMixed["foo"] = "bar";

try {
    $strToMixed->union($bitset);
    echo "FAIL: should throw\n";
} catch (Exception $e) {
    echo "union on STRING_TO_MIXED: " . $e->getMessage() . "\n";
}
?>
--EXPECT--
union on non-BITSET: Set operations are only supported on BITSET arrays
union with non-BITSET: The other Judy array must also be a BITSET
intersect on non-BITSET: Set operations are only supported on BITSET arrays
diff on non-BITSET: Set operations are only supported on BITSET arrays
xor on non-BITSET: Set operations are only supported on BITSET arrays
union on STRING_TO_MIXED: Set operations are only supported on BITSET arrays
