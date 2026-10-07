--TEST--
averageValues() contract: empty->NULL, presence mean, hand-computed means, throw cells (+ missing sumValues() throw cells)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Contract table for averageValues(), plus the sumValues() throw cells that
 * sum_values_001.phpt does not cover (PACKED / HASH-agnostic MIXED / ENTRY,
 * and the throw-when-empty path).
 *
 * Pinned current behavior (probed):
 * - averageValues() on an EMPTY array returns NULL for every type — the
 *   count==0 return precedes the type check, so even INT_TO_MIXED is NULL.
 * - sumValues() checks the type FIRST: it throws on a non-integer-valued type
 *   even when empty, while the same empty array's averageValues() is NULL.
 *   That asymmetry is real and pinned below.
 * - integer-valued types: mean is a float (15.0), hand-computed.
 * - BITSET: presence-only — an unset key is not a stored element and cannot
 *   enter the mean; one present element → avg 1.0, sum 1.
 * - filled non-integer-valued types throw the exact messages. */

$ALL = ['BITSET', 'INT_TO_INT', 'INT_TO_PACKED', 'INT_TO_MIXED',
    'STRING_TO_INT', 'STRING_TO_MIXED', 'STRING_TO_INT_HASH',
    'STRING_TO_MIXED_HASH', 'STRING_TO_INT_ADAPTIVE',
    'STRING_TO_MIXED_ADAPTIVE', 'STRING_TO_ENTRY'];

$AVG_MSG = 'averageValues() is only supported for integer-valued Judy types';
$SUM_MSG = 'sumValues() is only supported for integer-valued Judy types';

echo "== empty arrays (avg/sum) ==\n";
foreach ($ALL as $t) {
    $e = new Judy(constant('Judy::' . $t));
    $avg = $e->averageValues();
    $sum = 'NULL';
    try { $sum = var_export($e->sumValues(), true); }
    catch (\Exception $x) { $sum = ($x->getMessage() === $SUM_MSG) ? 'THROW' : 'WRONG-MSG'; }
    echo "$t: avg=" . ($avg === null ? 'NULL' : 'WRONG(' . var_export($avg, true) . ')')
       . " sum=$sum\n";
}

echo "== integer-valued, filled [10, 20] ==\n";
foreach (['INT_TO_INT', 'STRING_TO_INT', 'STRING_TO_INT_HASH',
          'STRING_TO_INT_ADAPTIVE'] as $t) {
    $j = new Judy(constant('Judy::' . $t));
    if (str_starts_with($t, 'INT_')) { $j[1] = 10; $j[2] = 20; }
    else { $j['a'] = 10; $j['b'] = 20; }
    printf("%s: avg=%s sum=%d\n", $t,
        var_export($j->averageValues(), true), $j->sumValues());
}

echo "== non-integer mean ==\n";
$j = new Judy(Judy::STRING_TO_INT);
$j['a'] = 1; $j['b'] = 2;
printf("STRING_TO_INT [1, 2]: avg=%s sum=%d\n",
    var_export($j->averageValues(), true), $j->sumValues());

echo "== BITSET presence ==\n";
$b = new Judy(Judy::BITSET);
$b[5] = true;              // BITSET stores presence only: an unset key is not a
                           // stored element, so it cannot enter the mean at all
printf("one present key: avg=%s sum=%d\n",
    var_export($b->averageValues(), true), $b->sumValues());

echo "== filled, non-integer-valued: both throw ==\n";
foreach (['INT_TO_PACKED', 'INT_TO_MIXED', 'STRING_TO_MIXED',
          'STRING_TO_MIXED_HASH', 'STRING_TO_MIXED_ADAPTIVE',
          'STRING_TO_ENTRY'] as $t) {
    $j = new Judy(constant('Judy::' . $t));
    $k1 = str_starts_with($t, 'INT_') ? 1 : 'a';
    $k2 = str_starts_with($t, 'INT_') ? 2 : 'b';
    $j[$k1] = ($t === 'STRING_TO_ENTRY') ? 'e1' : (str_starts_with($t, 'INT_') ? 10 : 'x');
    $j[$k2] = ($t === 'STRING_TO_ENTRY') ? 'e2' : (str_starts_with($t, 'INT_') ? 20 : 'y');

    try { $j->averageValues(); $avg = 'NO-THROW'; }
    catch (\Exception $x) { $avg = ($x->getMessage() === $AVG_MSG) ? 'THROW' : 'WRONG-MSG(' . $x->getMessage() . ')'; }
    try { $j->sumValues(); $sum = 'NO-THROW'; }
    catch (\Exception $x) { $sum = ($x->getMessage() === $SUM_MSG) ? 'THROW' : 'WRONG-MSG(' . $x->getMessage() . ')'; }
    echo "$t: avg=$avg sum=$sum\n";
}
echo "done\n";
?>
--EXPECT--
== empty arrays (avg/sum) ==
BITSET: avg=NULL sum=0
INT_TO_INT: avg=NULL sum=0
INT_TO_PACKED: avg=NULL sum=THROW
INT_TO_MIXED: avg=NULL sum=THROW
STRING_TO_INT: avg=NULL sum=0
STRING_TO_MIXED: avg=NULL sum=THROW
STRING_TO_INT_HASH: avg=NULL sum=0
STRING_TO_MIXED_HASH: avg=NULL sum=THROW
STRING_TO_INT_ADAPTIVE: avg=NULL sum=0
STRING_TO_MIXED_ADAPTIVE: avg=NULL sum=THROW
STRING_TO_ENTRY: avg=NULL sum=THROW
== integer-valued, filled [10, 20] ==
INT_TO_INT: avg=15.0 sum=30
STRING_TO_INT: avg=15.0 sum=30
STRING_TO_INT_HASH: avg=15.0 sum=30
STRING_TO_INT_ADAPTIVE: avg=15.0 sum=30
== non-integer mean ==
STRING_TO_INT [1, 2]: avg=1.5 sum=3
== BITSET presence ==
one present key: avg=1.0 sum=1
== filled, non-integer-valued: both throw ==
INT_TO_PACKED: avg=THROW sum=THROW
INT_TO_MIXED: avg=THROW sum=THROW
STRING_TO_MIXED: avg=THROW sum=THROW
STRING_TO_MIXED_HASH: avg=THROW sum=THROW
STRING_TO_MIXED_ADAPTIVE: avg=THROW sum=THROW
STRING_TO_ENTRY: avg=THROW sum=THROW
done
