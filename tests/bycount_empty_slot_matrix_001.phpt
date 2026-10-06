--TEST--
byCount() / empty-slot matrix: documented nulls on string-keyed types, full coverage on INT_TO_PACKED
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Matrix per plan Step 2.4:
 * - byCount() and the four empty-slot methods on the three string-keyed INT
 *   types return documented `null` — pinned AS-IS; changing them to throw
 *   would break documented behavior (AGENTS.md / API.md: "Returns null for
 *   string-keyed types").
 * - The same methods on INT_TO_PACKED: documented to work, previously ZERO
 *   coverage in this suite.
 * - byCount() edge cells: byCount(0) -> null, byCount(n > count) -> null,
 *   empty array -> null. byCount is 1-based positional (byCount(1) = first
 *   element present).
 *
 * lastEmpty() with no argument returns -1 whenever -1 is unset: the optional
 * $index defaults to -1 in C (zend_long zl_index = -1) and the call means
 * "greatest unset index at or below $index". -1 is a REAL key in this
 * extension's unsigned integer ordering (AGENTS.md: -1 addresses the maximum
 * index) and it is unset, so -1 comes back. It is a real search, not a
 * constant: when the array STORES -1, lastEmpty() returns -2 (pinned below).
 * nextEmpty/prevEmpty take an int $index even on string-keyed arrays (a
 * string index is an arginfo TypeError); with an int index they return the
 * documented null. */

echo "== string-keyed INT types: byCount (documented null) ==\n";
foreach (['STRING_TO_INT', 'STRING_TO_INT_HASH', 'STRING_TO_INT_ADAPTIVE'] as $t) {
    $j = new Judy(constant('Judy::' . $t));
    $j['a'] = 1;
    $j['b'] = 2;
    $r = $j->byCount(1);
    echo "$t byCount(1): " . ($r === null ? 'null' : 'WRONG(' . json_encode($r) . ')') . "\n";
}

echo "== string-keyed INT types: empty-slot (documented null) ==\n";
foreach (['STRING_TO_INT', 'STRING_TO_INT_HASH', 'STRING_TO_INT_ADAPTIVE'] as $t) {
    $j = new Judy(constant('Judy::' . $t));
    $j['a'] = 1;
    printf("%s: firstEmpty=%s lastEmpty=%s nextEmpty(0)=%s prevEmpty(10)=%s\n",
        $t,
        json_encode($j->firstEmpty()), json_encode($j->lastEmpty()),
        json_encode($j->nextEmpty(0)), json_encode($j->prevEmpty(10)));
}

echo "== byCount on INT_TO_PACKED ==\n";
$p = new Judy(Judy::INT_TO_PACKED);
$p[10] = 1;
$p[20] = 2;
$p[30] = 3;
printf("keys[10,20,30]: byCount(0)=%s byCount(1)=%s byCount(3)=%s byCount(4)=%s\n",
    json_encode($p->byCount(0)), json_encode($p->byCount(1)),
    json_encode($p->byCount(3)), json_encode($p->byCount(4)));
$pe = new Judy(Judy::INT_TO_PACKED);
printf("empty: byCount(1)=%s\n", json_encode($pe->byCount(1)));

echo "== empty-slot on INT_TO_PACKED ==\n";
printf("keys[10,20,30]: firstEmpty=%s nextEmpty(10)=%s lastEmpty()=%s lastEmpty(30)=%s prevEmpty(20)=%s\n",
    json_encode($p->firstEmpty()), json_encode($p->nextEmpty(10)),
    json_encode($p->lastEmpty()), json_encode($p->lastEmpty(30)),
    json_encode($p->prevEmpty(20)));
printf("empty: firstEmpty=%s nextEmpty(0)=%s prevEmpty(10)=%s lastEmpty()=%s lastEmpty(7)=%s\n",
    json_encode($pe->firstEmpty()), json_encode($pe->nextEmpty(0)),
    json_encode($pe->prevEmpty(10)), json_encode($pe->lastEmpty()),
    json_encode($pe->lastEmpty(7)));

echo "== lastEmpty() is a real search: -1 stored -> -2 ==\n";
$neg = new Judy(Judy::INT_TO_PACKED);
$neg[-1] = 1;
$neg[10] = 10;
printf("keys[-1,10]: lastEmpty()=%s\n", json_encode($neg->lastEmpty()));

echo "done\n";
?>
--EXPECT--
== string-keyed INT types: byCount (documented null) ==
STRING_TO_INT byCount(1): null
STRING_TO_INT_HASH byCount(1): null
STRING_TO_INT_ADAPTIVE byCount(1): null
== string-keyed INT types: empty-slot (documented null) ==
STRING_TO_INT: firstEmpty=null lastEmpty=null nextEmpty(0)=null prevEmpty(10)=null
STRING_TO_INT_HASH: firstEmpty=null lastEmpty=null nextEmpty(0)=null prevEmpty(10)=null
STRING_TO_INT_ADAPTIVE: firstEmpty=null lastEmpty=null nextEmpty(0)=null prevEmpty(10)=null
== byCount on INT_TO_PACKED ==
keys[10,20,30]: byCount(0)=null byCount(1)=10 byCount(3)=30 byCount(4)=null
empty: byCount(1)=null
== empty-slot on INT_TO_PACKED ==
keys[10,20,30]: firstEmpty=0 nextEmpty(10)=11 lastEmpty()=-1 lastEmpty(30)=29 prevEmpty(20)=19
empty: firstEmpty=0 nextEmpty(0)=1 prevEmpty(10)=9 lastEmpty()=-1 lastEmpty(7)=7
== lastEmpty() is a real search: -1 stored -> -2 ==
keys[-1,10]: lastEmpty()=-2
done
