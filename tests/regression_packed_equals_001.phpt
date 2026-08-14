--TEST--
Regression: INT_TO_PACKED equals() terminates and compares correctly
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// equals() for INT_TO_PACKED previously used `continue` on an equal-slot
// shortcut, which skipped the loop advance and could loop forever. Verify it
// terminates and gives correct equality across scalar/string/complex packed
// values.
function mk(array $pairs) {
    $j = new Judy(Judy::INT_TO_PACKED);
    foreach ($pairs as $k => $v) { $j[$k] = $v; }
    return $j;
}
$a = mk([1 => 10, 2 => "str", 3 => [1, 2, 3], 4 => 3.5]);
$b = mk([1 => 10, 2 => "str", 3 => [1, 2, 3], 4 => 3.5]);
$c = mk([1 => 10, 2 => "str", 3 => [1, 2, 9], 4 => 3.5]);

echo "a equals b: " . ($a->equals($b) ? "yes" : "no") . "\n";
echo "a equals c: " . ($a->equals($c) ? "yes" : "no") . "\n";
echo "empty equals empty: " . ((new Judy(Judy::INT_TO_PACKED))->equals(new Judy(Judy::INT_TO_PACKED)) ? "yes" : "no") . "\n";
echo "done\n";
?>
--EXPECT--
a equals b: yes
a equals c: no
empty equals empty: yes
done
