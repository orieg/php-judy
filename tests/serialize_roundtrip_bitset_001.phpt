--TEST--
Judy BITSET serialize/unserialize roundtrip
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::BITSET);
$j[1] = true;
$j[5] = true;
$j[100] = true;
$j[1000] = true;

$serialized = serialize($j);
echo "Serialized OK\n";

$restored = unserialize($serialized);

echo "Type: " . $restored->getType() . " (BITSET=" . Judy::BITSET . ")\n";
echo "Count: " . $restored->count() . "\n";

// Verify all indices
echo "Has 1: " . var_export(isset($restored[1]), true) . "\n";
echo "Has 5: " . var_export(isset($restored[5]), true) . "\n";
echo "Has 100: " . var_export(isset($restored[100]), true) . "\n";
echo "Has 1000: " . var_export(isset($restored[1000]), true) . "\n";
echo "Has 50: " . var_export(isset($restored[50]), true) . "\n";

// Verify iteration
foreach ($restored as $k => $v) {
    echo "  $k => " . var_export($v, true) . "\n";
}
?>
--EXPECT--
Serialized OK
Type: 1 (BITSET=1)
Count: 4
Has 1: true
Has 5: true
Has 100: true
Has 1000: true
Has 50: false
  1 => true
  5 => true
  100 => true
  1000 => true
