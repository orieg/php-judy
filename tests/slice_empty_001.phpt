--TEST--
Judy slice() - empty source, empty range, and reversed range
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Empty source array
$empty = new Judy(Judy::INT_TO_INT);
$result = $empty->slice(0, 100);
echo "Empty source count: " . $result->count() . "\n";
echo "Empty source type: " . $result->getType() . " (INT_TO_INT=" . Judy::INT_TO_INT . ")\n";

// Reversed range (start > end) should return empty
$j = new Judy(Judy::BITSET);
$j[1] = true;
$j[5] = true;
$j[10] = true;
$result2 = $j->slice(10, 1);
echo "Reversed range count: " . $result2->count() . "\n";

// Empty string source
$empty_str = new Judy(Judy::STRING_TO_INT);
$result3 = $empty_str->slice("a", "z");
echo "Empty string source count: " . $result3->count() . "\n";

// Reversed string range
$js = new Judy(Judy::STRING_TO_INT);
$js["banana"] = 1;
$js["cherry"] = 2;
$result4 = $js->slice("z", "a");
echo "Reversed string range count: " . $result4->count() . "\n";

// Type error: string arguments on integer-keyed array (should still work, coerces to 0)
$ji = new Judy(Judy::INT_TO_INT);
$ji[0] = 100;
$ji[5] = 200;
$result5 = $ji->slice(0, 5);
echo "Normal int slice count: " . $result5->count() . "\n";

// Empty BITSET
$eb = new Judy(Judy::BITSET);
$result6 = $eb->slice(0, 100);
echo "Empty BITSET count: " . $result6->count() . "\n";

// STRING_TO_MIXED reversed
$jsm = new Judy(Judy::STRING_TO_MIXED);
$jsm["foo"] = "bar";
$result7 = $jsm->slice("z", "a");
echo "Reversed STRING_TO_MIXED count: " . $result7->count() . "\n";

// Type error: pass non-string to string-keyed slice
$jsi = new Judy(Judy::STRING_TO_INT);
$jsi["foo"] = 1;
try {
    $jsi->slice(1, 2);
    echo "FAIL: should have thrown\n";
} catch (\TypeError $e) {
    echo "Type error caught: " . $e->getMessage() . "\n";
}
?>
--EXPECT--
Empty source count: 0
Empty source type: 2 (INT_TO_INT=2)
Reversed range count: 0
Empty string source count: 0
Reversed string range count: 0
Normal int slice count: 2
Empty BITSET count: 0
Reversed STRING_TO_MIXED count: 0
Type error caught: Judy::slice() expects string arguments for string-keyed arrays
