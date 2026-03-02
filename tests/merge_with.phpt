--TEST--
Judy mergeWith() - in-place merge
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php

echo "=== INT_TO_INT merge ===\n";
$a = new Judy(Judy::INT_TO_INT);
$a[1] = 10;
$a[2] = 20;
$a[3] = 30;

$b = new Judy(Judy::INT_TO_INT);
$b[2] = 200;
$b[4] = 400;

$a->mergeWith($b);
echo "size: " . $a->size() . "\n";
echo "1: " . $a[1] . "\n";
echo "2: " . $a[2] . "\n";  // overwritten by $b
echo "3: " . $a[3] . "\n";
echo "4: " . $a[4] . "\n";  // new from $b
echo "b unchanged size: " . $b->size() . "\n";

echo "\n=== STRING_TO_INT merge ===\n";
$s1 = new Judy(Judy::STRING_TO_INT);
$s1["foo"] = 1;
$s1["bar"] = 2;

$s2 = new Judy(Judy::STRING_TO_INT);
$s2["bar"] = 22;
$s2["baz"] = 33;

$s1->mergeWith($s2);
echo "size: " . $s1->size() . "\n";
echo "foo: " . $s1["foo"] . "\n";
echo "bar: " . $s1["bar"] . "\n";  // overwritten
echo "baz: " . $s1["baz"] . "\n";  // new

echo "\n=== BITSET merge ===\n";
$b1 = new Judy(Judy::BITSET);
$b1[10] = true;
$b1[20] = true;

$b2 = new Judy(Judy::BITSET);
$b2[20] = true;
$b2[30] = true;

$b1->mergeWith($b2);
echo "size: " . $b1->size() . "\n";
echo "has 10: " . (isset($b1[10]) ? "yes" : "no") . "\n";
echo "has 20: " . (isset($b1[20]) ? "yes" : "no") . "\n";
echo "has 30: " . (isset($b1[30]) ? "yes" : "no") . "\n";

echo "\n=== STRING_TO_INT_ADAPTIVE merge ===\n";
$a1 = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
$a1["abc"] = 1;        // short key
$a1["long_key_123"] = 2;  // long key

$a2 = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
$a2["abc"] = 11;       // overwrite short
$a2["xyz"] = 33;       // new short
$a2["another_long_key"] = 44;  // new long

$a1->mergeWith($a2);
echo "size: " . $a1->size() . "\n";
echo "abc: " . $a1["abc"] . "\n";
echo "long_key_123: " . $a1["long_key_123"] . "\n";
echo "xyz: " . $a1["xyz"] . "\n";
echo "another_long_key: " . $a1["another_long_key"] . "\n";

echo "\n=== Self-merge is no-op ===\n";
$self = new Judy(Judy::INT_TO_INT);
$self[1] = 100;
$self->mergeWith($self);
echo "size: " . $self->size() . "\n";
echo "1: " . $self[1] . "\n";

echo "\n=== Type mismatch ===\n";
$int_arr = new Judy(Judy::INT_TO_INT);
$str_arr = new Judy(Judy::STRING_TO_INT);
try {
    $int_arr->mergeWith($str_arr);
    echo "ERROR: should have thrown\n";
} catch (Exception $e) {
    echo "caught: " . (strpos($e->getMessage(), "incompatible key types") !== false ? "ok" : $e->getMessage()) . "\n";
}

echo "\n=== INT_TO_MIXED merge ===\n";
$m1 = new Judy(Judy::INT_TO_MIXED);
$m1[1] = "hello";
$m1[2] = [1, 2, 3];

$m2 = new Judy(Judy::INT_TO_MIXED);
$m2[2] = "replaced";
$m2[3] = 42;

$m1->mergeWith($m2);
echo "size: " . $m1->size() . "\n";
echo "1: " . $m1[1] . "\n";
echo "2: " . $m1[2] . "\n";
echo "3: " . $m1[3] . "\n";

echo "\nDone.\n";
?>
--EXPECT--
=== INT_TO_INT merge ===
size: 4
1: 10
2: 200
3: 30
4: 400
b unchanged size: 2

=== STRING_TO_INT merge ===
size: 3
foo: 1
bar: 22
baz: 33

=== BITSET merge ===
size: 3
has 10: yes
has 20: yes
has 30: yes

=== STRING_TO_INT_ADAPTIVE merge ===
size: 4
abc: 11
long_key_123: 2
xyz: 33
another_long_key: 44

=== Self-merge is no-op ===
size: 1
1: 100

=== Type mismatch ===
caught: ok

=== INT_TO_MIXED merge ===
size: 3
1: hello
2: replaced
3: 42

Done.
