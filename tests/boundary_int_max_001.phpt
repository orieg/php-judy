--TEST--
Judy boundary: PHP_INT_MAX as key and value for INT_TO_INT
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);

// PHP_INT_MAX as value
$j[0] = PHP_INT_MAX;
echo "Value PHP_INT_MAX: " . ($j[0] === PHP_INT_MAX ? "yes" : "no") . "\n";

// PHP_INT_MIN as value
$j[1] = PHP_INT_MIN;
echo "Value PHP_INT_MIN: " . ($j[1] === PHP_INT_MIN ? "yes" : "no") . "\n";

// Large integer key
$j[PHP_INT_MAX - 1] = 42;
echo "Key PHP_INT_MAX-1: " . $j[PHP_INT_MAX - 1] . "\n";

// PHP_INT_MAX as key
$j[PHP_INT_MAX] = 99;
echo "Key PHP_INT_MAX: " . $j[PHP_INT_MAX] . "\n";

// Zero key and value
$j[0] = 0;
echo "Value 0 at key 0: " . ($j[0] === 0 ? "yes" : "no") . "\n";
echo "Key 0 exists: " . (isset($j[0]) ? "yes" : "no") . "\n";

// Count
echo "Count: " . $j->count() . "\n";
?>
--EXPECT--
Value PHP_INT_MAX: yes
Value PHP_INT_MIN: yes
Key PHP_INT_MAX-1: 42
Key PHP_INT_MAX: 99
Value 0 at key 0: yes
Key 0 exists: yes
Count: 4
