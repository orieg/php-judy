--TEST--
Judy BITSET jsonSerialize() - returns flat array of set indices
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::BITSET);
$j[1] = true;
$j[5] = true;
$j[10] = true;

$json = json_encode($j);
echo "JSON: $json\n";

// Verify it's a flat array
$decoded = json_decode($json, true);
var_dump($decoded);

// Empty BITSET
$empty = new Judy(Judy::BITSET);
echo "Empty: " . json_encode($empty) . "\n";
?>
--EXPECT--
JSON: [1,5,10]
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(5)
  [2]=>
  int(10)
}
Empty: []
