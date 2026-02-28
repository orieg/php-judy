--TEST--
Judy toArray on empty arrays returns empty array for all types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$types = [
    'BITSET' => Judy::BITSET,
    'INT_TO_INT' => Judy::INT_TO_INT,
    'INT_TO_MIXED' => Judy::INT_TO_MIXED,
    'STRING_TO_INT' => Judy::STRING_TO_INT,
    'STRING_TO_MIXED' => Judy::STRING_TO_MIXED,
];

foreach ($types as $name => $type) {
    $j = new Judy($type);
    $arr = $j->toArray();
    echo "$name: " . count($arr) . "\n";
}
?>
--EXPECT--
BITSET: 0
INT_TO_INT: 0
INT_TO_MIXED: 0
STRING_TO_INT: 0
STRING_TO_MIXED: 0
