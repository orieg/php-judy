--TEST--
Judy sumValues() for integer-valued types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// INT_TO_INT
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 10;
$j[1] = 20;
$j[2] = 30;
echo "INT_TO_INT sum: ";
var_dump($j->sumValues());

// STRING_TO_INT
$j2 = new Judy(Judy::STRING_TO_INT);
$j2["a"] = 100;
$j2["b"] = 200;
$j2["c"] = 300;
echo "STRING_TO_INT sum: ";
var_dump($j2->sumValues());

// BITSET (sum = population count)
$j3 = new Judy(Judy::BITSET);
$j3[1] = true;
$j3[5] = true;
$j3[10] = true;
echo "BITSET sum: ";
var_dump($j3->sumValues());

// Empty
$j4 = new Judy(Judy::INT_TO_INT);
echo "Empty sum: ";
var_dump($j4->sumValues());

// Type error for MIXED types
$j5 = new Judy(Judy::INT_TO_MIXED);
$j5[0] = "hello";
try {
    $j5->sumValues();
} catch (Exception $e) {
    echo "INT_TO_MIXED error: " . $e->getMessage() . "\n";
}
?>
--EXPECT--
INT_TO_INT sum: int(60)
STRING_TO_INT sum: int(600)
BITSET sum: int(3)
Empty sum: int(0)
INT_TO_MIXED error: sumValues() is only supported for integer-valued Judy types

