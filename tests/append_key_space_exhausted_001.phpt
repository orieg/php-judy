--TEST--
Judy append reports key-space exhaustion instead of wrapping onto key 0
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
/*
Append means "one past the highest key", in the unsigned order Judy indexes
by. When the maximum index is occupied there is no key above it, so the append
must fail loudly. PHP's own arrays take the same position:
$a[PHP_INT_MAX] = 1; $a[] = 2; is an Error, not a wrap.

Previously the next index was computed as (zend_long)last_idx + 1, which is
signed overflow at ZEND_LONG_MAX and wraps to 0 at the maximum index — so an
append silently overwrote whatever key 0 held and stored nothing new.
*/
$types = [
    'BITSET'        => [Judy::BITSET,        true],
    'INT_TO_INT'    => [Judy::INT_TO_INT,    42],
    'INT_TO_MIXED'  => [Judy::INT_TO_MIXED,  'a'],
    'INT_TO_PACKED' => [Judy::INT_TO_PACKED, 1.5],
];

foreach ($types as $name => [$type, $v]) {
    echo "== $name ==\n";

    // The maximum index is occupied, so append has nowhere to go. Key 0 must
    // survive and the population must not change.
    $j = new Judy($type);
    $j[0]  = $v;
    $j[-1] = $v;
    try {
        $j[] = $v;
        echo "no exception\n";
    } catch (Exception $e) {
        echo $e->getMessage(), "\n";
    }
    var_dump($j[0] === $v, $j->count() === 2);

    // Reached through the bulk API too, which has always stored negative keys.
    $b = Judy::fromArray($type, $type === Judy::BITSET ? [-1] : [-1 => $v]);
    try {
        $b[] = $v;
        echo "no exception\n";
    } catch (Exception $e) {
        echo $e->getMessage(), "\n";
    }
    var_dump($b->count() === 1, isset($b[0]));

    // PHP_INT_MAX is NOT the ceiling: in unsigned order the next key is
    // PHP_INT_MIN, which is free, so this append succeeds.
    $m = new Judy($type);
    $m[PHP_INT_MAX] = $v;
    $m[] = $v;
    var_dump($m->keys() === [PHP_INT_MAX, PHP_INT_MIN]);
}
?>
--EXPECT--
== BITSET ==
Judy: cannot append, the integer key space is exhausted (the highest index is already occupied)
bool(true)
bool(true)
Judy: cannot append, the integer key space is exhausted (the highest index is already occupied)
bool(true)
bool(false)
bool(true)
== INT_TO_INT ==
Judy: cannot append, the integer key space is exhausted (the highest index is already occupied)
bool(true)
bool(true)
Judy: cannot append, the integer key space is exhausted (the highest index is already occupied)
bool(true)
bool(false)
bool(true)
== INT_TO_MIXED ==
Judy: cannot append, the integer key space is exhausted (the highest index is already occupied)
bool(true)
bool(true)
Judy: cannot append, the integer key space is exhausted (the highest index is already occupied)
bool(true)
bool(false)
bool(true)
== INT_TO_PACKED ==
Judy: cannot append, the integer key space is exhausted (the highest index is already occupied)
bool(true)
bool(true)
Judy: cannot append, the integer key space is exhausted (the highest index is already occupied)
bool(true)
bool(false)
bool(true)
