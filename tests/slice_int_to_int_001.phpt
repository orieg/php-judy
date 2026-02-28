--TEST--
Judy INT_TO_INT slice() - range extraction
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 100;
$j[5] = 200;
$j[10] = 300;
$j[15] = 400;
$j[20] = 500;

// Slice middle range
$result = $j->slice(5, 15);
echo "Middle slice count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k => $v\n";
}

// Slice full range
$result2 = $j->slice(0, 20);
echo "Full range count: " . $result2->count() . "\n";

// Slice single element
$result3 = $j->slice(10, 10);
echo "Single element count: " . $result3->count() . "\n";
foreach ($result3 as $k => $v) {
    echo "  $k => $v\n";
}

// Slice with no matching elements in range
$result4 = $j->slice(1, 4);
echo "No match count: " . $result4->count() . "\n";

// Verify original unmodified
echo "Original count: " . $j->count() . "\n";
?>
--EXPECT--
Middle slice count: 3
  5 => 200
  10 => 300
  15 => 400
Full range count: 5
Single element count: 1
  10 => 300
No match count: 0
Original count: 5
