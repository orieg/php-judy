--TEST--
Check for Judy STRING_TO_INT_HASH first/searchNext/last/prev methods
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_INT_HASH);

$judy["apple"]  = 1;
$judy["banana"] = 2;
$judy["cherry"] = 3;
$judy["date"]   = 4;
$judy["elder"]  = 5;

// first/last
$first = $judy->first();
$last  = $judy->last();
echo "First: $first\n";
echo "Last: $last\n";

// searchNext traversal
echo "Forward traversal:\n";
$key = $judy->first();
while ($key !== null) {
    echo "  $key => " . $judy[$key] . "\n";
    $key = $judy->searchNext($key);
}

// prev traversal
echo "Backward traversal:\n";
$key = $judy->last();
while ($key !== null) {
    echo "  $key => " . $judy[$key] . "\n";
    $key = $judy->prev($key);
}

echo "Done\n";
?>
--EXPECT--
First: apple
Last: elder
Forward traversal:
  apple => 1
  banana => 2
  cherry => 3
  date => 4
  elder => 5
Backward traversal:
  elder => 5
  date => 4
  cherry => 3
  banana => 2
  apple => 1
Done
