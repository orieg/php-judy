--TEST--
Judy INT_TO_PACKED PHP serialize/unserialize full roundtrip
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);
$j[0] = "hello";
$j[5] = 42;
$j[10] = [1, 2, 3];
$j[15] = true;
$j[20] = null;

$serialized = serialize($j);
$restored = unserialize($serialized);

echo "Type: " . $restored->getType() . " (INT_TO_PACKED=" . Judy::INT_TO_PACKED . ")\n";
echo "Count: " . $restored->count() . "\n";

foreach ($restored as $k => $v) {
    echo "  $k => " . var_export($v, true) . "\n";
}
echo "Done\n";
?>
--EXPECT--
Type: 6 (INT_TO_PACKED=6)
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
Done
