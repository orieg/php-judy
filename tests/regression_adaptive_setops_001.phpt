--TEST--
Regression: set operations work correctly for STRING_TO_INT_ADAPTIVE (SSO + long keys)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Adaptive set operations were rejected by the validator; the underlying
// existence checks used JHSG on the JudyL (SSO) store, which is wrong. Verify
// intersect/diff/xor/union now give correct results across short (SSO-packed)
// and long (JudyHS) keys.
$long1 = str_repeat('a', 40);
$long2 = str_repeat('b', 40);

function mk(array $keys): Judy {
    $j = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
    foreach ($keys as $k => $v) { $j[$k] = $v; }
    return $j;
}
function keys_of(Judy $j): array {
    $out = [];
    foreach ($j as $k => $v) { $out[$k] = $v; }
    ksort($out);
    return $out;
}

$a = mk(['x' => 1, 'yy' => 2, $long1 => 3, $long2 => 4]);   // short x,yy + long a,b
$b = mk(['yy' => 20, $long2 => 40, 'zzz' => 5]);            // short yy,zzz + long b

echo "intersect: " . json_encode(keys_of($a->intersect($b))) . "\n"; // yy, long2
echo "diff: " . json_encode(keys_of($a->diff($b))) . "\n";           // x, long1
echo "xor: " . json_encode(keys_of($a->xor($b))) . "\n";             // x, long1, zzz
echo "union: " . json_encode(keys_of($a->union($b))) . "\n";         // all (a wins)
?>
--EXPECT--
intersect: {"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb":4,"yy":2}
diff: {"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa":3,"x":1}
xor: {"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa":3,"x":1,"zzz":5}
union: {"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa":3,"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb":4,"x":1,"yy":2,"zzz":5}
