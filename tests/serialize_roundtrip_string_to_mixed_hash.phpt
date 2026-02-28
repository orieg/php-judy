--TEST--
Judy STRING_TO_MIXED_HASH serialize/unserialize roundtrip
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED_HASH);
$j["name"]   = "John";
$j["age"]    = 30;
$j["scores"] = [85, 92, 78];
$j["active"] = true;

$serialized = serialize($j);
$restored   = unserialize($serialized);

echo "Type: " . $restored->getType() . " (STRING_TO_MIXED_HASH=" . Judy::STRING_TO_MIXED_HASH . ")\n";
echo "Count: " . $restored->count() . "\n";

foreach ($restored as $k => $v) {
    echo "  $k => " . var_export($v, true) . "\n";
}
?>
--EXPECT--
Type: 7 (STRING_TO_MIXED_HASH=7)
Count: 4
  active => true
  age => 30
  name => 'John'
  scores => array (
  0 => 85,
  1 => 92,
  2 => 78,
)
