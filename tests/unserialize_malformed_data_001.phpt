--TEST--
__unserialize() rejects a valid type paired with non-array data
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* The single verified-there cell from plan Step 2.5: a VALID type constant
 * paired with data that is not an array throws today
 * ("Invalid serialization data for Judy array") — pinned for both a string
 * and an integer data payload.
 *
 * The main checkout carries a pending strict-validation `__unserialize` diff
 * (+35/−1, owned by another session; sprint Step 4.3 is report-only against
 * it). This cell is NOT among the ones that diff re-answers — it already
 * rejects — so pinning it cannot contradict the pending change.
 *
 * Deliberately NOT pinned (accepted today; pinning them would contradict the
 * pending strict-validation diff): a payload whose data array holds a string
 * key for an int-keyed type (accepted, E_WARNING only) and a wrong-shaped
 * entry array (accepted). Those belong to Step 4.3's report/follow-up. */

$cases = [
    'BITSET + string data'  => 'O:4:"Judy":2:{s:4:"type";i:1;s:4:"data";s:3:"foo";}',
    'INT_TO_INT + int data' => 'O:4:"Judy":2:{s:4:"type";i:2;s:4:"data";i:5;}',
];

foreach ($cases as $label => $payload) {
    try {
        unserialize($payload);
        echo "$label: NO-THROW\n";
    } catch (\Throwable $t) {
        echo "$label: " . get_class($t) . ' | ' . $t->getMessage() . "\n";
    }
}
echo "done\n";
?>
--EXPECT--
BITSET + string data: Exception | Invalid serialization data for Judy array
INT_TO_INT + int data: Exception | Invalid serialization data for Judy array
done
