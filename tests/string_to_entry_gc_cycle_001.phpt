--TEST--
GC cycle through STRING_TO_ENTRY values is collectable (get_gc ENTRY branch)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* get_gc had no TYPE_STRING_TO_ENTRY branch, so the cycle collector never saw
 * &entry->value: a cycle {Judy -> entry value -> object -> object->j -> Judy}
 * leaked — gc_collect_cycles() collected 0 for ENTRY while the STRING_TO_MIXED
 * control collected (verified pre-fix: 0 vs 1; at scale, 2000 such cycles were
 * fatal at the 128 MB memory limit).
 *
 * Both objects must be released from the outer scope (unset the local AND the
 * array) so the whole cycle is unreachable from outside; the assertion is
 * "collected >= 1" so it does not depend on how many zvals the collector
 * reports. Expired entries must be visible to GC too (they still hold a
 * refcount until pruneExpired/unset/dtor frees them) — the branch deliberately
 * does not filter on expiry. */

$make = function (int $type): int {
    gc_collect_cycles();               // drain anything left from a prior round
    $j = new Judy($type);
    $obj = new stdClass;
    $obj->j = $j;
    $j['k'] = $obj;
    unset($obj, $j);
    return gc_collect_cycles();
};

$control = $make(Judy::STRING_TO_MIXED);
$entry   = $make(Judy::STRING_TO_ENTRY);

echo 'control collected >= 1: ' . ($control >= 1 ? 'yes' : 'no(' . $control . ')') . "\n";
echo 'entry collected >= 1: ' . ($entry >= 1 ? 'yes' : 'no(' . $entry . ')') . "\n";
?>
--EXPECT--
control collected >= 1: yes
entry collected >= 1: yes
