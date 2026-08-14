--TEST--
Regression: fromArray/putAll on integer-keyed types reject non-integer keys (no hash-as-index)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Previously a string key on an integer-keyed type used num_key (the bucket
// hash) as the index — silent corruption. Now such keys are skipped with a
// warning; integer keys still insert normally.
foreach ([Judy::INT_TO_INT, Judy::INT_TO_MIXED, Judy::INT_TO_PACKED] as $type) {
    $j = Judy::fromArray($type, [0 => 'a', 'foo' => 'b', 5 => 'c']);
    echo "count: " . $j->count() . "\n";
    echo "has 0: " . (isset($j[0]) ? 'yes' : 'no') . ", has 5: " . (isset($j[5]) ? 'yes' : 'no') . "\n";
}
?>
--EXPECTF--
Warning: Judy::fromArray(): Judy integer-keyed type ignores non-integer array key "foo" in %s
count: 2
has 0: yes, has 5: yes

Warning: Judy::fromArray(): Judy integer-keyed type ignores non-integer array key "foo" in %s
count: 2
has 0: yes, has 5: yes

Warning: Judy::fromArray(): Judy integer-keyed type ignores non-integer array key "foo" in %s
count: 2
has 0: yes, has 5: yes
