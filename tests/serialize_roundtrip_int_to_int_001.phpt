--TEST--
Judy INT_TO_INT serialize/unserialize roundtrip
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 100;
$j[5] = 200;
$j[10] = 300;

$serialized = serialize($j);
$restored = unserialize($serialized);

echo "Type: " . $restored->getType() . " (INT_TO_INT=" . Judy::INT_TO_INT . ")\n";
echo "Count: " . $restored->count() . "\n";

foreach ($restored as $k => $v) {
    echo "  $k => $v\n";
}

// Verify independence
$restored[0] = 999;
echo "Original [0]: " . $j[0] . "\n";
echo "Restored [0]: " . $restored[0] . "\n";
?>
--EXPECT--
Type: 2 (INT_TO_INT=2)
Count: 3
  0 => 100
  5 => 200
  10 => 300
Original [0]: 100
Restored [0]: 999
