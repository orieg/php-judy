--TEST--
Judy INT_TO_PACKED overwrite: old packed value freed, new stored
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);

$j[0] = "original";
echo "before: " . var_export($j[0], true) . "\n";

$j[0] = "updated";
echo "after: " . var_export($j[0], true) . "\n";

$j[0] = [1, 2, 3];
echo "array: " . var_export($j[0], true) . "\n";

// Count should still be 1
echo "count: " . $j->count() . "\n";

echo "Done\n";
?>
--EXPECT--
before: 'original'
after: 'updated'
array: array (
  0 => 1,
  1 => 2,
  2 => 3,
)
count: 1
Done
