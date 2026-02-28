--TEST--
Judy increment throws exception on unsupported types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// BITSET
try {
    $j = new Judy(Judy::BITSET);
    $j->increment(5);
    echo "ERROR: no exception for BITSET\n";
} catch (Exception $e) {
    echo "BITSET: " . $e->getMessage() . "\n";
}

// INT_TO_MIXED
try {
    $j = new Judy(Judy::INT_TO_MIXED);
    $j->increment(5);
    echo "ERROR: no exception for INT_TO_MIXED\n";
} catch (Exception $e) {
    echo "INT_TO_MIXED: " . $e->getMessage() . "\n";
}

// STRING_TO_MIXED
try {
    $j = new Judy(Judy::STRING_TO_MIXED);
    $j->increment("key");
    echo "ERROR: no exception for STRING_TO_MIXED\n";
} catch (Exception $e) {
    echo "STRING_TO_MIXED: " . $e->getMessage() . "\n";
}
?>
--EXPECT--
BITSET: Judy::increment() is only supported for INT_TO_INT, STRING_TO_INT and STRING_TO_INT_HASH types
INT_TO_MIXED: Judy::increment() is only supported for INT_TO_INT, STRING_TO_INT and STRING_TO_INT_HASH types
STRING_TO_MIXED: Judy::increment() is only supported for INT_TO_INT, STRING_TO_INT and STRING_TO_INT_HASH types
