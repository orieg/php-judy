--TEST--
Regression: adaptive INT counter must not drift when value 0 is re-stored
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Value 0 is a legal stored value and must not be mistaken for "empty slot".
// Re-storing a key whose current value is 0 previously double-counted.
foreach (['short', 'a_much_longer_key_that_exceeds_sso_packing_threshold'] as $k) {
    $j = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
    $j[$k] = 0;
    $j[$k] = 5;   // overwrite: must NOT increment count again
    echo "count after overwrite of 0: " . $j->count() . "\n";
    echo "value: " . $j[$k] . "\n";

    $j[$k] = 0;   // overwrite back to 0
    $j[$k] = 0;   // and again
    echo "count after more overwrites: " . $j->count() . "\n";
}
?>
--EXPECT--
count after overwrite of 0: 1
value: 5
count after more overwrites: 1
count after overwrite of 0: 1
value: 5
count after more overwrites: 1
