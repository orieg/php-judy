--TEST--
Check for Judy STRING_TO_INT_HASH slice method
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_INT_HASH);
$judy["alpha"]   = 1;
$judy["beta"]    = 2;
$judy["charlie"] = 3;
$judy["delta"]   = 4;
$judy["echo"]    = 5;

// Slice a range
$sliced = $judy->slice("beta", "delta");
echo "Sliced count: " . $sliced->count() . "\n";

echo "Sliced contents:\n";
foreach ($sliced as $k => $v) {
    echo "  $k => $v\n";
}

// Verify type
echo "Sliced type: " . $sliced->getType() . "\n";

echo "Done\n";
?>
--EXPECT--
Sliced count: 3
Sliced contents:
  beta => 2
  charlie => 3
  delta => 4
Sliced type: 8
Done
