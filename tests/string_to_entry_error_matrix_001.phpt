--TEST--
STRING_TO_ENTRY + newer-type error matrix: NUL keys, wrong receivers, getAll/jsonSerialize/increment
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Error-contract matrix for the ENTRY methods and the newer types (plan
 * Step 2.5; every cell probed green against the current binary before pinning):
 * - get/getEntry/getExpiry/getFlags reject embedded-NUL keys with the
 *   uniform string-keyed message,
 * - the same four raise a TypeError naming the method when the RECEIVER is
 *   not a STRING_TO_ENTRY (string-keyed non-ENTRY and int-keyed both),
 * - getAll() happy path on ENTRY and STRING_TO_INT_ADAPTIVE: found keys map
 *   to their values, a missing key maps to null,
 * - jsonSerialize() (via json_encode) for ENTRY / STRING_TO_INT_HASH / both
 *   ADAPTIVE types: plain key => value object,
 * - increment() on the ADAPTIVE types throws the pinned "only supported for"
 *   message (STRING_TO_INT_HASH is supported and covered elsewhere).
 * NOT pinned (accepted today; would contradict the pending __unserialize
 * strict-validation diff in the main checkout): unserialize cells with a
 * string key for an int-keyed type (E_WARNING only) and wrong-shaped entries. */

$ENTRY = 'Judy STRING_TO_ENTRY keys must not contain embedded null bytes';
$INCR  = 'Judy::increment() is only supported for INT_TO_INT, STRING_TO_INT and STRING_TO_INT_HASH types';

echo "== STRING_TO_ENTRY happy path ==\n";
$e = new Judy(Judy::STRING_TO_ENTRY);
$e['a'] = 'va';
$e['b'] = 'vb';
echo 'get(a)=' . $e->get('a') . "\n";
echo 'getExpiry(a)=' . json_encode($e->getExpiry('a')) . "\n";
echo 'getFlags(a)=' . json_encode($e->getFlags('a')) . "\n";
echo 'getEntry(a)=' . json_encode($e->getEntry('a')) . "\n";
$ex = 'unset';
$fl = 'unset';
$e->get('a', $ex, $fl);
echo "get refs: expiresAt=" . json_encode($ex) . ' flags=' . json_encode($fl) . "\n";

echo "== NUL key rejected on all four ENTRY methods ==\n";
foreach (['get', 'getEntry', 'getExpiry', 'getFlags'] as $m) {
    try {
        $e->$m("a\0b");
        echo "$m: NO-THROW\n";
    } catch (\Throwable $t) {
        $ok = ($t->getMessage() === $ENTRY) ? 'THROW' : 'WRONG-MSG(' . $t->getMessage() . ')';
        echo "$m: " . get_class($t) . ' | ' . $ok . "\n";
    }
}

echo "== wrong receiver: TypeError on non-ENTRY arrays ==\n";
$mix = new Judy(Judy::STRING_TO_MIXED);
$mix['a'] = 1;
$int = new Judy(Judy::INT_TO_INT);
$int[1] = 1;
foreach (['get', 'getEntry', 'getExpiry', 'getFlags'] as $m) {
    foreach ([['STRING_TO_MIXED', $mix, 'a'], ['INT_TO_INT', $int, '1']] as [$label, $recv, $k]) {
        try {
            $recv->$m($k);
            echo "$m on $label: NO-THROW\n";
        } catch (\Throwable $t) {
            $want = "Judy::$m() is only supported for STRING_TO_ENTRY arrays";
            // Print the REAL class (not a label derived from the message) so a
            // class regression to Exception fails even with the message intact;
            // a wrong message surfaces as a WRONG-MSG suffix. EXPECT pins both.
            $cls = get_class($t);
            $sfx = ($t->getMessage() === $want) ? '' : ' | WRONG-MSG(' . $t->getMessage() . ')';
            echo "$m on $label: $cls$sfx\n";
        }
    }
}

echo "== getAll happy path ==\n";
echo 'ENTRY: ' . json_encode($e->getAll(['a', 'b', 'missing'])) . "\n";
$ad = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
$ad['x'] = 7;
$ad['y'] = 8;
echo 'ADAPTIVE: ' . json_encode($ad->getAll(['x', 'y', 'nope'])) . "\n";

echo "== jsonSerialize ==\n";
echo 'ENTRY: ' . json_encode($e) . "\n";
$h = new Judy(Judy::STRING_TO_INT_HASH);
$h['h1'] = 5;
echo 'STRING_TO_INT_HASH: ' . json_encode($h) . "\n";
echo 'STRING_TO_INT_ADAPTIVE: ' . json_encode($ad) . "\n";
$am = new Judy(Judy::STRING_TO_MIXED_ADAPTIVE);
$am['m'] = 's';
echo 'STRING_TO_MIXED_ADAPTIVE: ' . json_encode($am) . "\n";

echo "== increment on ADAPTIVE types throws ==\n";
foreach ([['STRING_TO_INT_ADAPTIVE', $ad, 'x'], ['STRING_TO_MIXED_ADAPTIVE', $am, 'm']] as [$label, $j, $k]) {
    try {
        $j->increment($k);
        echo "$label: NO-THROW\n";
    } catch (\Throwable $t) {
        $ok = ($t->getMessage() === $INCR) ? 'THROW' : 'WRONG-MSG(' . $t->getMessage() . ')';
        echo "$label: " . get_class($t) . " | $ok\n";
    }
}
echo "done\n";
?>
--EXPECT--
== STRING_TO_ENTRY happy path ==
get(a)=va
getExpiry(a)=0
getFlags(a)=0
getEntry(a)={"value":"va","expires_at":0,"flags":0,"is_expired":false}
get refs: expiresAt=0 flags=0
== NUL key rejected on all four ENTRY methods ==
get: Exception | THROW
getEntry: Exception | THROW
getExpiry: Exception | THROW
getFlags: Exception | THROW
== wrong receiver: TypeError on non-ENTRY arrays ==
get on STRING_TO_MIXED: TypeError
get on INT_TO_INT: TypeError
getEntry on STRING_TO_MIXED: TypeError
getEntry on INT_TO_INT: TypeError
getExpiry on STRING_TO_MIXED: TypeError
getExpiry on INT_TO_INT: TypeError
getFlags on STRING_TO_MIXED: TypeError
getFlags on INT_TO_INT: TypeError
== getAll happy path ==
ENTRY: {"a":"va","b":"vb","missing":null}
ADAPTIVE: {"x":7,"y":8,"nope":null}
== jsonSerialize ==
ENTRY: {"a":"va","b":"vb"}
STRING_TO_INT_HASH: {"h1":5}
STRING_TO_INT_ADAPTIVE: {"x":7,"y":8}
STRING_TO_MIXED_ADAPTIVE: {"m":"s"}
== increment on ADAPTIVE types throws ==
STRING_TO_INT_ADAPTIVE: Exception | THROW
STRING_TO_MIXED_ADAPTIVE: Exception | THROW
done
