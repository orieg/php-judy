--TEST--
Judy STRING_TO_MIXED_HASH fromArray/toArray roundtrip
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$input = [
    "banana" => 2,
    "apple"  => "fruit",
    "cherry" => [1, 2, 3],
    "date"   => true,
];

$j = Judy::fromArray(Judy::STRING_TO_MIXED_HASH, $input);

echo "Type: " . $j->getType() . " (STRING_TO_MIXED_HASH=" . Judy::STRING_TO_MIXED_HASH . ")\n";
echo "Count: " . $j->count() . "\n";

// toArray output is sorted alphabetically (via JudySL key_index)
var_dump($j->toArray());

// Roundtrip via putAll
$j2 = new Judy(Judy::STRING_TO_MIXED_HASH);
$j2->putAll($input);
echo "putAll count: " . $j2->count() . "\n";
var_dump($j2->toArray());

echo "Done\n";
?>
--EXPECT--
Type: 7 (STRING_TO_MIXED_HASH=7)
Count: 4
array(4) {
  ["apple"]=>
  string(5) "fruit"
  ["banana"]=>
  int(2)
  ["cherry"]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(2)
    [2]=>
    int(3)
  }
  ["date"]=>
  bool(true)
}
putAll count: 4
array(4) {
  ["apple"]=>
  string(5) "fruit"
  ["banana"]=>
  int(2)
  ["cherry"]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(2)
    [2]=>
    int(3)
  }
  ["date"]=>
  bool(true)
}
Done
