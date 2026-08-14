--TEST--
Regression: adaptive types iterate/getAll correctly (no JHSG-on-JudyL type confusion)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// getAll(), foreach (get_iterator), and manual rewind()/next() previously
// called JHSG on the JudyL `array` for adaptive types (type confusion / UB).
// Mix short (SSO-packed) and long (JudyHS) keys to exercise both stores.
$long = str_repeat('x', 40);
foreach ([Judy::STRING_TO_INT_ADAPTIVE, Judy::STRING_TO_MIXED_ADAPTIVE] as $type) {
    $j = new Judy($type);
    $j['a'] = ($type === Judy::STRING_TO_INT_ADAPTIVE) ? 1 : 'one';
    $j[$long] = ($type === Judy::STRING_TO_INT_ADAPTIVE) ? 2 : 'two';

    // getAll with an explicit key list
    $all = $j->getAll(['a', $long]);
    ksort($all);
    echo "getAll: " . json_encode($all) . "\n";

    // foreach (get_iterator path)
    $seen = [];
    foreach ($j as $k => $v) { $seen[$k] = $v; }
    ksort($seen);
    echo "foreach: " . json_encode($seen) . "\n";

    // manual Iterator rewind()/current()/next()
    $manual = [];
    for ($j->rewind(); $j->valid(); $j->next()) {
        $manual[$j->key()] = $j->current();
    }
    ksort($manual);
    echo "manual: " . json_encode($manual) . "\n";
}
?>
--EXPECT--
getAll: {"a":1,"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx":2}
foreach: {"a":1,"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx":2}
manual: {"a":1,"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx":2}
getAll: {"a":"one","xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx":"two"}
foreach: {"a":"one","xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx":"two"}
manual: {"a":"one","xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx":"two"}
