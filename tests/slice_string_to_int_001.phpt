--TEST--
Judy STRING_TO_INT slice() - range extraction by string key
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_INT);
$j["alpha"] = 1;
$j["beta"] = 2;
$j["delta"] = 3;
$j["epsilon"] = 4;
$j["gamma"] = 5;

// Slice alphabetically beta..epsilon (includes beta, delta, epsilon)
$result = $j->slice("beta", "epsilon");
echo "Slice count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k => $v\n";
}

// Slice single key
$result2 = $j->slice("gamma", "gamma");
echo "Single key count: " . $result2->count() . "\n";
foreach ($result2 as $k => $v) {
    echo "  $k => $v\n";
}

// Slice all
$result3 = $j->slice("a", "z");
echo "All count: " . $result3->count() . "\n";

// Slice with no matching keys
$result4 = $j->slice("aaa", "aab");
echo "No match count: " . $result4->count() . "\n";

// Verify original unmodified
echo "Original count: " . $j->size() . "\n";
?>
--EXPECT--
Slice count: 3
  beta => 2
  delta => 3
  epsilon => 4
Single key count: 1
  gamma => 5
All count: 5
No match count: 0
Original count: 5
