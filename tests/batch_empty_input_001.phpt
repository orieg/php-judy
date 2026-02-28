--TEST--
Judy batch operations with empty input arrays
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// putAll with empty array on existing data
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 100;
$j->putAll([]);
echo "putAll([]) count: " . $j->count() . "\n";
echo "Value preserved: " . $j[0] . "\n";

// getAll with empty array
$result = $j->getAll([]);
echo "getAll([]) result: ";
var_dump($result);

// fromArray with empty array
$j = Judy::fromArray(Judy::INT_TO_INT, []);
echo "fromArray empty count: " . $j->count() . "\n";
echo "fromArray empty type: " . $j->getType() . "\n";

$j = Judy::fromArray(Judy::STRING_TO_INT, []);
echo "fromArray STRING_TO_INT empty count: " . $j->count() . "\n";

$j = Judy::fromArray(Judy::BITSET, []);
echo "fromArray BITSET empty count: " . $j->count() . "\n";

// putAll empty on STRING_TO_INT
$j = new Judy(Judy::STRING_TO_INT);
$j["a"] = 1;
$j->putAll([]);
echo "STRING_TO_INT putAll([]) count: " . $j->count() . "\n";

// getAll empty on STRING_TO_INT
$result = $j->getAll([]);
echo "STRING_TO_INT getAll([]) result: ";
var_dump($result);
?>
--EXPECT--
putAll([]) count: 1
Value preserved: 100
getAll([]) result: array(0) {
}
fromArray empty count: 0
fromArray empty type: 2
fromArray STRING_TO_INT empty count: 0
fromArray BITSET empty count: 0
STRING_TO_INT putAll([]) count: 1
STRING_TO_INT getAll([]) result: array(0) {
}
