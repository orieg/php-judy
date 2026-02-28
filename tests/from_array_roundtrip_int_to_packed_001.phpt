--TEST--
Judy INT_TO_PACKED fromArray + toArray roundtrip
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$data = [0 => "hello", 5 => 42, 10 => [1, 2, 3], 15 => true];
$j = Judy::fromArray(Judy::INT_TO_PACKED, $data);

echo "Type: " . $j->getType() . " (INT_TO_PACKED=" . Judy::INT_TO_PACKED . ")\n";
echo "Count: " . $j->count() . "\n";

var_dump($j->toArray());
echo "Done\n";
?>
--EXPECT--
Type: 6 (INT_TO_PACKED=6)
Count: 4
array(4) {
  [0]=>
  string(5) "hello"
  [5]=>
  int(42)
  [10]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(2)
    [2]=>
    int(3)
  }
  [15]=>
  bool(true)
}
Done
