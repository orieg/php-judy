--TEST--
Judy jsonSerialize() - all empty types
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
    echo "$name: " . json_encode($j) . "\n";
}
?>
--EXPECT--
BITSET: []
INT_TO_INT: []
INT_TO_MIXED: []
STRING_TO_INT: []
STRING_TO_MIXED: []
