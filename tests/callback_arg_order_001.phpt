--TEST--
Callback contract: forEach/filter/map hand ($value, $key) on all 11 types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Pins the callback ARGUMENT ORDER of forEach()/filter()/map(): the callback
 * receives ($value, $key) — value first, key second. The implementation and
 * every existing test agree on this; the docs state it backwards (fixed in
 * Batch 5, Step 5.1). If the order ever flips, every line below prints FAIL
 * because the expected value and key are deliberately distinct in both type
 * and content (int-keyed: 42 vs 10; string-keyed: "VAL"/42 vs "aa"; BITSET:
 * true vs 10), so a swap cannot alias into a false OK.
 *
 * filter(): retention itself is the order assertion — the predicate only
 * returns true when BOTH args are in the pinned positions.
 * map(): args are side-recorded and the value passed through unchanged, so
 * no storage coercion can mask or fake an order failure. */

$types = ['BITSET', 'INT_TO_INT', 'INT_TO_PACKED', 'INT_TO_MIXED',
    'STRING_TO_INT', 'STRING_TO_MIXED', 'STRING_TO_INT_HASH',
    'STRING_TO_MIXED_HASH', 'STRING_TO_INT_ADAPTIVE',
    'STRING_TO_MIXED_ADAPTIVE', 'STRING_TO_ENTRY'];

foreach ($types as $t) {
    $j = new Judy(constant('Judy::' . $t));
    $intKeyed = ($t === 'BITSET' || str_starts_with($t, 'INT_'));
    $k = $intKeyed ? 10 : 'aa';
    $v = match ($t) {
        'BITSET' => true,
        'INT_TO_INT', 'STRING_TO_INT', 'STRING_TO_INT_HASH',
        'STRING_TO_INT_ADAPTIVE', 'INT_TO_PACKED' => 42,
        default => 'VAL',
    };
    $j[$k] = $v;

    $seen = [];
    $j->forEach(function ($a, $b) use (&$seen) { $seen[] = [$a, $b]; });
    $okF = (count($seen) === 1 && $seen[0][0] === $v && $seen[0][1] === $k);

    $fk = [];
    $f = $j->filter(function ($a, $b) use (&$fk, $k, $v) {
        $fk[] = [$a, $b];
        return $a === $v && $b === $k;
    });
    $okFlt = (count($fk) === 1 && $fk[0][0] === $v && $fk[0][1] === $k
              && count($f) === 1 && isset($f[$k]));

    $mk = [];
    $m = $j->map(function ($a, $b) use (&$mk) { $mk[] = [$a, $b]; return $a; });
    $okMap = (count($mk) === 1 && $mk[0][0] === $v && $mk[0][1] === $k
              && count($m) === 1 && $m[$k] === $v);

    printf("%s: forEach=%s filter=%s map=%s\n", $t,
        $okF ? 'OK' : 'FAIL(' . json_encode($seen) . ')',
        $okFlt ? 'OK' : 'FAIL(' . json_encode($fk) . '/' . count($f) . ')',
        $okMap ? 'OK' : 'FAIL(' . json_encode($mk) . ')');
}
echo "done\n";
?>
--EXPECT--
BITSET: forEach=OK filter=OK map=OK
INT_TO_INT: forEach=OK filter=OK map=OK
INT_TO_PACKED: forEach=OK filter=OK map=OK
INT_TO_MIXED: forEach=OK filter=OK map=OK
STRING_TO_INT: forEach=OK filter=OK map=OK
STRING_TO_MIXED: forEach=OK filter=OK map=OK
STRING_TO_INT_HASH: forEach=OK filter=OK map=OK
STRING_TO_MIXED_HASH: forEach=OK filter=OK map=OK
STRING_TO_INT_ADAPTIVE: forEach=OK filter=OK map=OK
STRING_TO_MIXED_ADAPTIVE: forEach=OK filter=OK map=OK
STRING_TO_ENTRY: forEach=OK filter=OK map=OK
done
