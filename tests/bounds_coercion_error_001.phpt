--TEST--
Wrong-category range bounds throw TypeError instead of coercing to a wrong-range read (Step 3.8)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Step 3.8: string bounds on int-keyed keys/values/toArray/slice/size used to
 * coerce through zval_get_long() ("a" -> 0) and silently read the WRONG RANGE
 * (keys("a","b") returned [0] whenever integer key 0 existed); int bounds on
 * string-keyed deleteRange() were coerced by Z_PARAM_STRING to their string
 * form and deleted the wrong keys. Both directions now throw a TypeError that
 * mirrors the already-throwing directions (slice int-bounds-on-string-keyed,
 * deleteRange string-bounds-on-int-keyed), never returning a wrong answer.
 *
 * The accepting contract is unchanged and pinned below too: integers and
 * numeric strings ("5" / "10" / "10.5") still name integer keys — the same
 * set Z_PARAM_LONG accepts in weak mode — and string bounds still work on
 * string-keyed arrays. The throwing calls leave their array untouched. */

function try_call(string $label, callable $fn): void {
    try {
        $fn();
        echo "$label: NO-THROW\n";
    } catch (\Throwable $e) {
        echo "$label: " . get_class($e) . " | " . $e->getMessage() . "\n";
    }
}

/* --- Int-keyed arrays reject string bounds (the wrong-range-read fix). --- */
$i = new Judy(Judy::INT_TO_INT);
$i[0] = 1; $i[5] = 2; $i[10] = 3;
try_call('keys($start)',   fn() => $i->keys('a', 'b'));
try_call('keys($end)',     fn() => $i->keys(0, 'x'));
try_call('values',         fn() => $i->values('a', 'b'));
try_call('toArray',        fn() => $i->toArray('a', 'b'));
try_call('size',           fn() => $i->size('a', 'b'));
try_call('slice($start)',  fn() => $i->slice('a', 'b'));
try_call('slice($end)',    fn() => $i->slice(5, 'x'));
try_call('keys non-numeric "0x10"', fn() => $i->keys('0x10', 10));
echo 'int-keyed intact: ' . json_encode($i->keys()) . "\n";

/* Numeric strings still name integer keys (sibling Z_PARAM_LONG contract). */
echo 'keys("5","10"): '  . json_encode($i->keys('5', '10')) . "\n";
echo 'slice("5","10"): ' . json_encode($i->slice('5', '10')->keys()) . "\n";
echo 'size("10.5", 100): ' . $i->size('10.5', 100) . "\n";
echo 'size(0, -1): '     . $i->size(0, -1) . "\n";
echo 'slice empty range count: ' . count($i->slice(10, 5)) . "\n";

/* BITSET is integer-keyed too. */
$b = new Judy(Judy::BITSET);
$b[3] = true;
try_call('BITSET keys string bound', fn() => $b->keys('a', 'b'));
echo 'bitset intact: ' . json_encode($b->keys()) . "\n";

/* --- String-keyed arrays reject integer bounds on deleteRange(). --- */
$s = new Judy(Judy::STRING_TO_INT);
$s['a'] = 1; $s['c'] = 3; $s['1'] = 10; $s['2'] = 20;
try_call('trie deleteRange($start)', fn() => $s->deleteRange(1, 2));
try_call('trie deleteRange($end)',   fn() => $s->deleteRange('a', 7));
echo 'trie intact: ' . json_encode($s->keys()) . "\n";

$a = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
$a['a'] = 1; $a['c'] = 3; $a['1'] = 10; $a['2'] = 20;
try_call('adaptive deleteRange($start)', fn() => $a->deleteRange(1, 2));
echo 'adaptive intact: ' . json_encode($a->keys()) . "\n";

$h = new Judy(Judy::STRING_TO_MIXED_HASH);
$h['a'] = 'x'; $h['c'] = 'y'; $h['3'] = 'z'; $h['4'] = 'w';
try_call('hash deleteRange(float)', fn() => $h->deleteRange(1.5, 3));
echo 'hash intact: ' . json_encode($h->keys()) . "\n";

/* String bounds still work on string-keyed deleteRange. */
$s2 = clone $s;
$s2->deleteRange('a', 'a');
echo 'trie deleteRange("a","a"): ' . json_encode($s2->keys()) . "\n";

echo "done\n";
?>
--EXPECT--
keys($start): TypeError | Judy::keys() expects integer arguments for integer-keyed arrays
keys($end): TypeError | Judy::keys() expects integer arguments for integer-keyed arrays
values: TypeError | Judy::values() expects integer arguments for integer-keyed arrays
toArray: TypeError | Judy::toArray() expects integer arguments for integer-keyed arrays
size: TypeError | Judy::size() expects integer arguments for integer-keyed arrays
slice($start): TypeError | Judy::slice() expects integer arguments for integer-keyed arrays
slice($end): TypeError | Judy::slice() expects integer arguments for integer-keyed arrays
keys non-numeric "0x10": TypeError | Judy::keys() expects integer arguments for integer-keyed arrays
int-keyed intact: [0,5,10]
keys("5","10"): [5,10]
slice("5","10"): [5,10]
size("10.5", 100): 1
size(0, -1): 3
slice empty range count: 0
BITSET keys string bound: TypeError | Judy::keys() expects integer arguments for integer-keyed arrays
bitset intact: [3]
trie deleteRange($start): TypeError | Judy::deleteRange() expects string arguments for string-keyed arrays
trie deleteRange($end): TypeError | Judy::deleteRange() expects string arguments for string-keyed arrays
trie intact: ["1","2","a","c"]
adaptive deleteRange($start): TypeError | Judy::deleteRange() expects string arguments for string-keyed arrays
adaptive intact: ["1","2","a","c"]
hash deleteRange(float): TypeError | Judy::deleteRange() expects string arguments for string-keyed arrays
hash intact: ["3","4","a","c"]
trie deleteRange("a","a"): ["1","2","c"]
done