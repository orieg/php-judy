--TEST--
Judy STRING_TO_MIXED serialize/unserialize roundtrip
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED);
$j["name"] = "John";
$j["age"] = 30;
$j["scores"] = [85, 92, 78];
$j["active"] = true;

$serialized = serialize($j);
$restored = unserialize($serialized);

echo "Type: " . $restored->getType() . " (STRING_TO_MIXED=" . Judy::STRING_TO_MIXED . ")\n";
echo "Count: " . $restored->count() . "\n";

foreach ($restored as $k => $v) {
    echo "  $k => " . var_export($v, true) . "\n";
}
?>
--EXPECT--
Type: 5 (STRING_TO_MIXED=5)
Count: 4
  active => true
  age => 30
  name => 'John'
  scores => array (
  0 => 85,
  1 => 92,
  2 => 78,
)
