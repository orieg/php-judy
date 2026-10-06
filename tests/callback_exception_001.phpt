--TEST--
Callback exceptions propagate from forEach/filter/map, stop iteration, leave the source intact
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* A predicate/callback that throws must:
 *   1. propagate out of forEach(), filter() and map() (caught once, clean),
 *   2. STOP iteration — the visit counter freezes at the throw (2), the
 *      remaining elements never reach the callback,
 *   3. leave the SOURCE array untouched (count and values as populated),
 *   4. leave the object usable afterwards (a further write + a full foreach
 *      run with no lingering pending exception).
 * Covers one type per storage class: int-keyed (INT_TO_INT), plain trie
 * (STRING_TO_MIXED) and HASH (STRING_TO_MIXED_HASH). Probed: all nine cells
 * behave identically on the current binary. */

$cases = [
    'INT_TO_INT'          => [1, 2, 3],
    'STRING_TO_MIXED'     => ['a', 'b', 'c'],
    'STRING_TO_MIXED_HASH'=> ['a', 'b', 'c'],
];

foreach ($cases as $t => $keys) {
    $intKeyed = str_starts_with($t, 'INT_');
    foreach (['forEach', 'filter', 'map'] as $op) {
        $j = new Judy(constant('Judy::' . $t));
        foreach ($keys as $i => $kk) {
            $j[$kk] = $intKeyed ? ($i + 1) * 10 : ('v' . ($i + 1));
        }

        $visits = 0;
        $caught = 'none';
        try {
            $j->$op(function ($v, $kk) use (&$visits) {
                if (++$visits === 2) {
                    throw new \RuntimeException('boom');
                }
                return true;
            });
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }

        $intact = (count($j) === 3
                   && $j[$keys[0]] === ($intKeyed ? 10 : 'v1')
                   && $j[$keys[2]] === ($intKeyed ? 30 : 'v3'));

        $j[$intKeyed ? 99 : 'zz'] = 999;
        $n = 0;
        foreach ($j as $_ => $__) { $n++; }

        printf("%s %s: caught=%s visits=%d intact=%s usable=%d\n",
               $t, $op, $caught, $visits, $intact ? 'yes' : 'NO', $n);
    }
}
echo "done\n";
?>
--EXPECT--
INT_TO_INT forEach: caught=boom visits=2 intact=yes usable=4
INT_TO_INT filter: caught=boom visits=2 intact=yes usable=4
INT_TO_INT map: caught=boom visits=2 intact=yes usable=4
STRING_TO_MIXED forEach: caught=boom visits=2 intact=yes usable=4
STRING_TO_MIXED filter: caught=boom visits=2 intact=yes usable=4
STRING_TO_MIXED map: caught=boom visits=2 intact=yes usable=4
STRING_TO_MIXED_HASH forEach: caught=boom visits=2 intact=yes usable=4
STRING_TO_MIXED_HASH filter: caught=boom visits=2 intact=yes usable=4
STRING_TO_MIXED_HASH map: caught=boom visits=2 intact=yes usable=4
done
