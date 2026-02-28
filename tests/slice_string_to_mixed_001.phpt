--TEST--
Judy STRING_TO_MIXED slice() - range extraction by string key with mixed values
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED);
$j["apple"] = "fruit";
$j["banana"] = 42;
$j["cherry"] = [1, 2];
$j["date"] = true;
$j["elderberry"] = null;

// Slice banana..date
$result = $j->slice("banana", "date");
echo "Slice count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k => " . var_export($v, true) . "\n";
}

// Verify deep copy
$result["banana"] = "changed";
echo "Original banana: " . var_export($j["banana"], true) . "\n";

// Slice all
$result2 = $j->slice("a", "z");
echo "All count: " . $result2->count() . "\n";
?>
--EXPECT--
Slice count: 3
  banana => 42
  cherry => array (
  0 => 1,
  1 => 2,
)
  date => true
Original banana: 42
All count: 5
