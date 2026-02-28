--TEST--
Judy BITSET slice() - range extraction
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::BITSET);
$j[1] = true;
$j[5] = true;
$j[10] = true;
$j[15] = true;
$j[20] = true;

// Slice middle range
$result = $j->slice(5, 15);
echo "Middle slice count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k\n";
}

// Slice exact boundaries
$result2 = $j->slice(1, 20);
echo "Full range count: " . $result2->count() . "\n";

// Slice single element
$result3 = $j->slice(10, 10);
echo "Single element count: " . $result3->count() . "\n";
foreach ($result3 as $k => $v) {
    echo "  $k\n";
}

// Slice with no matching elements in range
$result4 = $j->slice(2, 4);
echo "No match count: " . $result4->count() . "\n";

// Verify original unmodified
echo "Original count: " . $j->count() . "\n";
?>
--EXPECT--
Middle slice count: 3
  5
  10
  15
Full range count: 5
Single element count: 1
  10
No match count: 0
Original count: 5
