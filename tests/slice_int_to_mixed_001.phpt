--TEST--
Judy INT_TO_MIXED slice() - range extraction with mixed values
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_MIXED);
$j[0] = "hello";
$j[5] = 42;
$j[10] = [1, 2, 3];
$j[15] = true;
$j[20] = null;

// Slice middle range
$result = $j->slice(5, 15);
echo "Middle slice count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k => " . var_export($v, true) . "\n";
}

// Verify deep copy (modifying result doesn't affect original)
$result[5] = "changed";
echo "Original [5]: " . var_export($j[5], true) . "\n";

// Slice that includes null value
$result2 = $j->slice(15, 20);
echo "Null range count: " . $result2->count() . "\n";
foreach ($result2 as $k => $v) {
    echo "  $k => " . var_export($v, true) . "\n";
}
?>
--EXPECT--
Middle slice count: 3
  5 => 42
  10 => array (
  0 => 1,
  1 => 2,
  2 => 3,
)
  15 => true
Original [5]: 42
Null range count: 2
  15 => true
  20 => NULL
