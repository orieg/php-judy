--TEST--
Judy increment for STRING_TO_INT_HASH: shares offsetSet's slot path (key_index, counter, preconditions)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// STRING_TO_INT_HASH keeps two stores: a JudyHS value store and a JudySL
// key_index that drives ordered traversal. increment() writes a value in
// place, so it must register keys exactly the way offsetSet() does or
// iteration and point lookup will disagree.
$j = new Judy(Judy::STRING_TO_INT_HASH);

// New keys via increment() only.
echo "increment('pear'): " . $j->increment("pear") . "\n";
echo "increment('apple', 5): " . $j->increment("apple", 5) . "\n";
echo "Count: " . $j->count() . "\n";

// Every key created by increment() must be reachable by ordered traversal,
// in sorted order, with the value point lookup reports.
foreach ($j as $k => $v) {
    echo "iter $k => $v (offsetGet " . $j[$k] . ")\n";
}

// Overwrite path: increment() on an existing key must not touch the count
// and must stay visible to traversal.
echo "increment('pear', 10): " . $j->increment("pear", 10) . "\n";
echo "Count: " . $j->count() . "\n";
foreach ($j as $k => $v) {
    echo "iter $k => $v\n";
}

// Mixed with offsetSet on the same keys, both directions.
$j["pear"] = 100;
echo "after offsetSet: increment('pear') = " . $j->increment("pear") . "\n";
$j->increment("cherry", 3);
$j["cherry"] = $j["cherry"] + 1;
echo "Count: " . $j->count() . "\n";
var_dump($j->toArray());

// Negative amounts and zero-valued keys: 0 is a legal stored value and must
// not be mistaken for an absent key on the next increment.
$j->increment("zero", 0);
echo "zero present: " . var_export(isset($j["zero"]), true) . "\n";
echo "Count: " . $j->count() . "\n";
echo "increment('zero', -4): " . $j->increment("zero", -4) . "\n";
echo "Count: " . $j->count() . "\n";

// Delete then recreate via increment().
unset($j["apple"]);
echo "Count after unset: " . $j->count() . "\n";
echo "increment('apple', 7): " . $j->increment("apple", 7) . "\n";
echo "Count: " . $j->count() . "\n";
echo "keys: " . implode(",", array_keys($j->toArray())) . "\n";

// Preconditions are the same ones offsetSet() enforces.
try {
    $j->increment("a\0b");
} catch (Exception $e) {
    echo "NUL: " . $e->getMessage() . "\n";
}
try {
    $j->increment(str_repeat("x", 65536));
} catch (Exception $e) {
    echo "LONG: " . $e->getMessage() . "\n";
}
echo "Count unchanged: " . $j->count() . "\n";
?>
--EXPECT--
increment('pear'): 1
increment('apple', 5): 5
Count: 2
iter apple => 5 (offsetGet 5)
iter pear => 1 (offsetGet 1)
increment('pear', 10): 11
Count: 2
iter apple => 5
iter pear => 11
after offsetSet: increment('pear') = 101
Count: 3
array(3) {
  ["apple"]=>
  int(5)
  ["cherry"]=>
  int(4)
  ["pear"]=>
  int(101)
}
zero present: true
Count: 4
increment('zero', -4): -4
Count: 4
Count after unset: 3
increment('apple', 7): 7
Count: 4
keys: apple,cherry,pear,zero
NUL: Judy STRING_TO_INT_HASH keys must not contain embedded null bytes
LONG: Judy string key length (65536) exceeds maximum of 65535 bytes
Count unchanged: 4
