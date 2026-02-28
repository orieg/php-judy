--TEST--
Judy STRING_TO_INT serialize/unserialize roundtrip
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_INT);
$j["apple"] = 1;
$j["banana"] = 2;
$j["cherry"] = 3;

$serialized = serialize($j);
$restored = unserialize($serialized);

echo "Type: " . $restored->getType() . " (STRING_TO_INT=" . Judy::STRING_TO_INT . ")\n";
echo "Count: " . $restored->count() . "\n";

foreach ($restored as $k => $v) {
    echo "  $k => $v\n";
}

// Verify key lookup
echo "banana: " . $restored["banana"] . "\n";
?>
--EXPECT--
Type: 4 (STRING_TO_INT=4)
Count: 3
  apple => 1
  banana => 2
  cherry => 3
banana: 2
