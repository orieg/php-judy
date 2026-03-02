--TEST--
Judy Phase 3: Adaptive Types and SSO
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
echo "--- STRING_TO_INT_ADAPTIVE ---
";
$j = new Judy(Judy::STRING_TO_INT_ADAPTIVE);

echo "Setting short keys (< 8 bytes):
";
$j["abc"] = 123;
$j["defg"] = 456;
$j["h"] = 789;

echo "Setting long keys (>= 8 bytes):
";
$j["long_key_123"] = 1000;
$j["another_long_key"] = 2000;

echo "Retrieving keys:
";
echo "abc: " . $j["abc"] . "
";
echo "long_key_123: " . $j["long_key_123"] . "
";

echo "Size: " . $j->size() . "
";

echo "Iteration:
";
foreach($j as $k => $v) {
    echo "  $k => $v
";
}

echo "
--- STRING_TO_MIXED_ADAPTIVE ---
";
$j = new Judy(Judy::STRING_TO_MIXED_ADAPTIVE);
$j["short"] = ["a" => 1];
$j["very_very_long_key_name"] = "big value";

echo "short: " . (is_array($j["short"]) ? "array" : "not array") . "
";
echo "long: " . $j["very_very_long_key_name"] . "
";

?>
--EXPECT--
--- STRING_TO_INT_ADAPTIVE ---
Setting short keys (< 8 bytes):
Setting long keys (>= 8 bytes):
Retrieving keys:
abc: 123
long_key_123: 1000
Size: 5
Iteration:
  abc => 123
  another_long_key => 2000
  defg => 456
  h => 789
  long_key_123 => 1000

--- STRING_TO_MIXED_ADAPTIVE ---
short: array
long: big value
