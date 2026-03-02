--TEST--
Judy populationCount() for integer-keyed types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// BITSET — full range
$j = new Judy(Judy::BITSET);
for ($i = 0; $i < 100; $i++) {
    $j[$i] = true;
}
echo "BITSET full: " . $j->populationCount() . "\n";

// BITSET — sub-range
echo "BITSET 10-49: " . $j->populationCount(10, 49) . "\n";
echo "BITSET 0-0: " . $j->populationCount(0, 0) . "\n";

// INT_TO_INT — full range
$j2 = new Judy(Judy::INT_TO_INT);
$j2[10] = 1;
$j2[20] = 2;
$j2[30] = 3;
$j2[40] = 4;
echo "INT_TO_INT full: " . $j2->populationCount() . "\n";

// INT_TO_INT — sub-range
echo "INT_TO_INT 15-35: " . $j2->populationCount(15, 35) . "\n";
echo "INT_TO_INT 10-10: " . $j2->populationCount(10, 10) . "\n";

// Empty
$j3 = new Judy(Judy::BITSET);
echo "Empty: " . $j3->populationCount() . "\n";

// Type error for string-keyed
$j4 = new Judy(Judy::STRING_TO_INT);
$j4["a"] = 1;
try {
    $j4->populationCount();
} catch (Exception $e) {
    echo "STRING_TO_INT error: " . $e->getMessage() . "\n";
}
?>
--EXPECT--
BITSET full: 100
BITSET 10-49: 40
BITSET 0-0: 1
INT_TO_INT full: 4
INT_TO_INT 15-35: 2
INT_TO_INT 10-10: 1
Empty: 0
STRING_TO_INT error: populationCount() is only supported for integer-keyed Judy types

