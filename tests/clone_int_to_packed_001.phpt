--TEST--
Judy INT_TO_PACKED clone independence (modify clone, original unchanged)
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);
$j[0] = "original";
$j[1] = [1, 2, 3];

$clone = clone $j;

// Modify clone
$clone[0] = "modified";
$clone[2] = "new";
unset($clone[1]);

// Verify original is unchanged
echo "original[0]: " . var_export($j[0], true) . "\n";
echo "original[1]: " . var_export($j[1], true) . "\n";
echo "original count: " . $j->count() . "\n";

// Verify clone has changes
echo "clone[0]: " . var_export($clone[0], true) . "\n";
echo "clone[1]: " . var_export($clone[1], true) . "\n";
echo "clone[2]: " . var_export($clone[2], true) . "\n";
echo "clone count: " . $clone->count() . "\n";

echo "Done\n";
?>
--EXPECT--
original[0]: 'original'
original[1]: array (
  0 => 1,
  1 => 2,
  2 => 3,
)
original count: 2
clone[0]: 'modified'
clone[1]: NULL
clone[2]: 'new'
clone count: 2
Done
