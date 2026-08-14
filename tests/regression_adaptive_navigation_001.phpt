--TEST--
Regression: first/last/searchNext/prev work on adaptive types (not silent NULL)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Adaptive types were missing from the first/last/searchNext/prev type
// dispatch and returned NULL on a populated array. Exercise both SSO-packed
// short keys and long (JudyHS) keys.
$long = str_repeat('z', 40);
foreach ([Judy::STRING_TO_INT_ADAPTIVE, Judy::STRING_TO_MIXED_ADAPTIVE] as $type) {
    $j = new Judy($type);
    $j['b']   = 1;
    $j['d']   = 2;
    $j[$long] = 3;   // long key sorts after 'b','d'

    var_dump($j->first());
    var_dump($j->last());
    var_dump($j->searchNext('b'));
    var_dump($j->prev($long));
}
?>
--EXPECT--
string(1) "b"
string(40) "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz"
string(1) "d"
string(1) "d"
string(1) "b"
string(40) "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz"
string(1) "d"
string(1) "d"
