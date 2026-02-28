--TEST--
Judy constructor with invalid type throws exception
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Invalid type value
try {
    $j = new Judy(999);
    echo "No exception for type 999\n";
} catch (Exception $e) {
    echo "Exception for 999: " . $e->getMessage() . "\n";
}

// Negative type value
try {
    $j = new Judy(-1);
    echo "No exception for type -1\n";
} catch (Exception $e) {
    echo "Exception for -1: " . $e->getMessage() . "\n";
}

// Zero type value
try {
    $j = new Judy(0);
    echo "No exception for type 0\n";
} catch (Exception $e) {
    echo "Exception for 0: " . $e->getMessage() . "\n";
}

// Valid types work normally
$j = new Judy(Judy::BITSET);
echo "BITSET created: " . ($j->getType() == Judy::BITSET ? "yes" : "no") . "\n";
$j = new Judy(Judy::INT_TO_INT);
echo "INT_TO_INT created: " . ($j->getType() == Judy::INT_TO_INT ? "yes" : "no") . "\n";
?>
--EXPECTF--
Exception for 999: Judy::__construct(): Not a valid Judy type. Please check the documentation for valid Judy type constant.
Exception for -1: Judy::__construct(): Not a valid Judy type. Please check the documentation for valid Judy type constant.
Exception for 0: Judy::__construct(): Not a valid Judy type. Please check the documentation for valid Judy type constant.
BITSET created: yes
INT_TO_INT created: yes
