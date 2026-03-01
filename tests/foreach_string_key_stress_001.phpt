--TEST--
Judy STRING_TO_MIXED foreach stress test with varying key lengths
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_MIXED);

// Insert 200 keys of varying lengths
$expected = [];
for ($i = 0; $i < 200; $i++) {
    $key = str_repeat(chr(65 + ($i % 26)), ($i % 50) + 1);
    // Make keys unique by appending index
    $key .= sprintf("_%04d", $i);
    $val = "value_$i";
    $j[$key] = $val;
    $expected[$key] = $val;
}
ksort($expected);

echo "Count: " . $j->count() . "\n";

// Verify all keys and values match
$actual = [];
foreach ($j as $k => $v) {
    $actual[$k] = $v;
}

// Check count matches
echo "Iterated: " . count($actual) . "\n";
echo "Match: " . ($actual === $expected ? "yes" : "no") . "\n";

// Second iteration (rewind test)
$count2 = 0;
foreach ($j as $k => $v) {
    $count2++;
}
echo "Second iteration: " . $count2 . "\n";

// Iteration over empty array
$j->free();
$count3 = 0;
foreach ($j as $k => $v) {
    $count3++;
}
echo "Empty iteration: " . $count3 . "\n";
?>
--EXPECT--
Count: 200
Iterated: 200
Match: yes
Second iteration: 200
Empty iteration: 0
