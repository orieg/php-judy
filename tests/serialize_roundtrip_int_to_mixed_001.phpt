--TEST--
Judy INT_TO_MIXED serialize/unserialize roundtrip
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_MIXED);
$j[0] = "hello";
$j[5] = 42;
$j[10] = [1, 2, 3];
$j[15] = true;
$j[20] = null;

$serialized = serialize($j);
$restored = unserialize($serialized);

echo "Type: " . $restored->getType() . " (INT_TO_MIXED=" . Judy::INT_TO_MIXED . ")\n";
echo "Count: " . $restored->count() . "\n";

foreach ($restored as $k => $v) {
    echo "  $k => " . var_export($v, true) . "\n";
}
?>
--EXPECT--
Type: 3 (INT_TO_MIXED=3)
Count: 5
  0 => 'hello'
  5 => 42
  10 => array (
  0 => 1,
  1 => 2,
  2 => 3,
)
  15 => true
  20 => NULL
