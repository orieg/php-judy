--TEST--
Judy negative integer offsets are stored as keys for all integer-keyed types
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
/*
Invariant: for any integer $k, after $j[$k] = $v, isset($j[$k]) is true and
$j[$k] returns $v. Integer keys are unsigned machine words, so a negative PHP
int addresses the top of the key space and reads back unchanged.

Before 2.5.0 every key in [PHP_INT_MIN, -1] was discarded by offsetSet and the
value appended at the end of the array instead.
*/
$types = [
    'BITSET'        => [Judy::BITSET,        true, true],
    'INT_TO_INT'    => [Judy::INT_TO_INT,    42,   43],
    'INT_TO_MIXED'  => [Judy::INT_TO_MIXED,  'a',  'b'],
    'INT_TO_PACKED' => [Judy::INT_TO_PACKED, 1.5,  2.5],
];

foreach ($types as $name => [$type, $v1, $v2]) {
    echo "== $name ==\n";

    // 1. Round-trip at the requested key.
    $j = new Judy($type);
    $j[-1] = $v1;
    var_dump(isset($j[-1]), $j[-1] === $v1, $j->count() === 1);

    // 2. Boundary sweep: the whole negative half is addressable, and
    //    PHP_INT_MIN must not collapse onto key 0.
    $j = new Judy($type);
    $j[-1] = $v1;
    $j[-2] = $v1;
    $j[PHP_INT_MIN] = $v1;
    $j[PHP_INT_MIN + 1] = $v1;
    var_dump($j->count() === 4, isset($j[0]));

    // 3. Unsigned ordering: negatives sort after every non-negative key.
    $j = new Judy($type);
    $j[0] = $v1;
    $j[5] = $v1;
    $j[-1] = $v1;
    $j[PHP_INT_MIN] = $v1;
    var_dump($j->keys() === [0, 5, PHP_INT_MIN, -1]);

    // 4. Write/read/unset symmetry: all three target the same key.
    $j = new Judy($type);
    $j[-1] = $v1;
    unset($j[-1]);
    var_dump(isset($j[-1]), $j->count() === 0);

    // 5. Overwriting a negative key replaces it rather than adding one.
    $j = new Judy($type);
    $j[-1] = $v1;
    $j[-1] = $v2;
    var_dump($j->count() === 1, $j[-1] === $v2);

    // 6. Append still works after a negative write that is not at the
    //    ceiling, and follows unsigned order.
    $j = new Judy($type);
    $j[] = $v1;
    $j[-2] = $v1;
    $j[] = $v2;
    var_dump($j->keys() === [0, -2, -1]);
}

// 7. Cross-entry-point agreement: the array-subscript write must build the
//    same array as the bulk APIs, which have always stored negative keys.
echo "== agreement ==\n";
$data = [-1 => 5, 0 => 1, PHP_INT_MIN => 9];

$sub = new Judy(Judy::INT_TO_INT);
foreach ($data as $k => $v) { $sub[$k] = $v; }

$from = Judy::fromArray(Judy::INT_TO_INT, $data);
$put  = new Judy(Judy::INT_TO_INT);
$put->putAll($data);

$inc = new Judy(Judy::INT_TO_INT);
foreach ($data as $k => $v) { $inc->increment($k, $v); }

var_dump($sub->equals($from), $sub->equals($put), $sub->equals($inc));
var_dump($sub->equals(unserialize(serialize($sub))));

// 8. The idiomatic copy loop is idempotent.
$copy = new Judy(Judy::INT_TO_INT);
foreach ($from as $k => $v) { $copy[$k] = $v; }
var_dump($from->equals($copy));

// 9. map()/filter() preserve negative keys rather than relocating them.
var_dump($from->map(fn($v) => $v)->keys() === $from->keys());
var_dump($from->filter(fn($v) => true)->keys() === $from->keys());
?>
--EXPECT--
== BITSET ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
== INT_TO_INT ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
== INT_TO_MIXED ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
== INT_TO_PACKED ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
== agreement ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
