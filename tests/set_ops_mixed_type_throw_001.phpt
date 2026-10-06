--TEST--
Set operations throw on same-type non-integer-valued operands, leaving both operands untouched
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* The unsupported-type throw cell for union/intersect/diff/xor.
 * bitset_setops_type_error_001.phpt pins only ONE same-type unsupported cell
 * (STRING_TO_MIXED union) plus the cross-category mismatch message; the cells
 * below are otherwise unpinned: every op on two INT_TO_MIXED, two
 * INT_TO_PACKED, two STRING_TO_MIXED and two STRING_TO_ENTRY arrays.
 * All must throw the identical "BITSET and integer-valued" message, and the
 * operands must be untouched afterwards — the validation precedes any mutation,
 * so a half-applied set op (a silent merge) would fail the intact check.
 * NOTE: INT_TO_PACKED is thrown despite being integer-valued — the check is
 * type-based and excludes the packed representation; pinned as-is. */

$msg = 'Set operations are only supported on BITSET and integer-valued arrays';

foreach (['INT_TO_MIXED', 'INT_TO_PACKED', 'STRING_TO_MIXED', 'STRING_TO_ENTRY'] as $t) {
    $intKeyed = str_starts_with($t, 'INT_');
    $k1 = $intKeyed ? 1 : 'a';
    $k2 = $intKeyed ? 2 : 'b';
    $v1 = ($t === 'STRING_TO_ENTRY') ? 'e1' : ($intKeyed ? 11 : 'v1');
    $v2 = ($t === 'STRING_TO_ENTRY') ? 'e2' : ($intKeyed ? 22 : 'v2');

    foreach (['union', 'intersect', 'diff', 'xor'] as $op) {
        $a = new Judy(constant('Judy::' . $t));
        $b = new Judy(constant('Judy::' . $t));
        $a[$k1] = $v1;
        $b[$k2] = $v2;

        try {
            $a->$op($b);
            echo "$t $op: NO-THROW\n";
        } catch (\Exception $e) {
            echo "$t $op: " . ($e->getMessage() === $msg ? 'THROW' : 'WRONG-MSG(' . $e->getMessage() . ')') . "\n";
        }

        $intact = (count($a) === 1 && count($b) === 1
                   && $a[$k1] === $v1 && $b[$k2] === $v2);
        echo "  operands intact: " . ($intact ? 'yes' : 'NO') . "\n";
    }
}
echo "done\n";
?>
--EXPECT--
INT_TO_MIXED union: THROW
  operands intact: yes
INT_TO_MIXED intersect: THROW
  operands intact: yes
INT_TO_MIXED diff: THROW
  operands intact: yes
INT_TO_MIXED xor: THROW
  operands intact: yes
INT_TO_PACKED union: THROW
  operands intact: yes
INT_TO_PACKED intersect: THROW
  operands intact: yes
INT_TO_PACKED diff: THROW
  operands intact: yes
INT_TO_PACKED xor: THROW
  operands intact: yes
STRING_TO_MIXED union: THROW
  operands intact: yes
STRING_TO_MIXED intersect: THROW
  operands intact: yes
STRING_TO_MIXED diff: THROW
  operands intact: yes
STRING_TO_MIXED xor: THROW
  operands intact: yes
STRING_TO_ENTRY union: THROW
  operands intact: yes
STRING_TO_ENTRY intersect: THROW
  operands intact: yes
STRING_TO_ENTRY diff: THROW
  operands intact: yes
STRING_TO_ENTRY xor: THROW
  operands intact: yes
done
