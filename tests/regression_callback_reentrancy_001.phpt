--TEST--
Regression: forEach/filter/map callbacks may re-enter without corrupting iteration
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// The callback iterator used the shared key_scratch as its cursor; a callback
// that re-entered an operation touching that buffer (first/last/toArray/nested
// forEach) corrupted the outer loop. Verify every element is visited exactly
// once even when the callback re-enters.
$long = str_repeat('q', 40);
foreach ([Judy::STRING_TO_INT, Judy::STRING_TO_INT_HASH, Judy::STRING_TO_INT_ADAPTIVE] as $type) {
    $j = new Judy($type);
    foreach (['a', 'bb', 'ccc', $long] as $i => $k) { $j[$k] = $i; }

    $seen = [];
    // forEach passes ($value, $key) to the callback.
    $j->forEach(function ($v, $k) use ($j, &$seen) {
        // Re-enter operations that write key_scratch mid-iteration.
        $j->first();
        $j->last();
        $j->toArray();
        $seen[$k] = $v;
    });
    ksort($seen);
    echo "$type: visited " . count($seen) . " -> " . implode(',', array_keys($seen)) . "\n";
}
?>
--EXPECT--
4: visited 4 -> a,bb,ccc,qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
8: visited 4 -> a,bb,ccc,qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
10: visited 4 -> a,bb,ccc,qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
