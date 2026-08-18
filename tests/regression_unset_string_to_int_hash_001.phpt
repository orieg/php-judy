--TEST--
Judy STRING_TO_INT_HASH: unset() bookkeeping for present, absent and empty-array keys
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
/* The unset path for STRING_TO_INT_HASH deletes straight out of the JudyHS
 * value store and gates the key_index removal, the element counter and the
 * key-byte accounting on JudyHSDel's return value. This test pins that
 * bookkeeping: both stores and the counter must agree after every operation,
 * and an absent key must move nothing at all. */

function state(Judy $j): string
{
    /* keys() walks the key_index; the offsetGet probes read the value store;
     * count() reads the element counter. All three must agree. */
    $keys = $j->keys();
    $via_store = [];
    foreach ($keys as $k) {
        $via_store[] = $k . "=" . ($j[$k] ?? "MISSING");
    }
    $walk = [];
    foreach ($j as $k => $v) {
        $walk[] = $k . "=" . $v;
    }
    return sprintf(
        "count=%d size=%d keys=[%s] store=[%s] walk=[%s]",
        count($j),
        $j->size(),
        implode(",", $keys),
        implode(",", $via_store),
        implode(",", $walk)
    );
}

foreach ([false, true] as $opt) {
    echo "--- optimizeIteration=", var_export($opt, true), " ---\n";
    $j = new Judy(Judy::STRING_TO_INT_HASH, optimizeIteration: $opt);
    echo "honoured: ", var_export($j->isIterationOptimized(), true), "\n";

    /* Unset on an empty array: no error, no bookkeeping movement. */
    unset($j["nothing"]);
    echo "empty:   ", state($j), " mem=", $j->memoryUsage(), "\n";

    $j["alpha"] = 1;
    $j["beta"]  = 2;
    $j["gamma"] = 3;
    $filled_mem = $j->memoryUsage();
    echo "filled:  ", state($j), "\n";

    /* Absent key: nothing moves — not the counter, not the key_index, not
     * the byte accounting. This is the case that used to be short-circuited
     * by a JHSG probe and is now decided by JudyHSDel's return value. */
    unset($j["delta"]);
    echo "absent:  ", state($j),
         " mem_unchanged=", var_export($j->memoryUsage() === $filled_mem, true), "\n";

    /* Present key: dropped from the value store, from the ordered key index
     * and from the counter, exactly once. */
    unset($j["beta"]);
    echo "present: ", state($j), " isset_beta=", var_export(isset($j["beta"]), true),
         " mem_shrunk=", var_export($j->memoryUsage() < $filled_mem, true), "\n";

    /* Second unset of the same key is now the absent case. */
    $after = $j->memoryUsage();
    unset($j["beta"]);
    echo "again:   ", state($j),
         " mem_unchanged=", var_export($j->memoryUsage() === $after, true), "\n";

    /* Ordered navigation must not see the removed key either. */
    echo "nav:     first=", var_export($j->first(), true),
         " next=", var_export($j->searchNext("alpha"), true),
         " last=", var_export($j->last(), true), "\n";

    /* Drain: counter, both stores and the byte accounting return to empty. */
    unset($j["alpha"], $j["gamma"]);
    echo "drained: ", state($j), " mem=", $j->memoryUsage(), "\n";

    /* Unsetting again on the now-empty array stays a no-op. */
    unset($j["alpha"]);
    echo "post:    ", state($j), " mem=", $j->memoryUsage(), "\n";

    /* Reinsert after the drain to prove nothing was left behind. */
    $j["alpha"] = 11;
    echo "reinsert:", state($j), "\n";
}

echo "Done\n";
?>
--EXPECT--
--- optimizeIteration=false ---
honoured: false
empty:   count=0 size=0 keys=[] store=[] walk=[] mem=0
filled:  count=3 size=3 keys=[alpha,beta,gamma] store=[alpha=1,beta=2,gamma=3] walk=[alpha=1,beta=2,gamma=3]
absent:  count=3 size=3 keys=[alpha,beta,gamma] store=[alpha=1,beta=2,gamma=3] walk=[alpha=1,beta=2,gamma=3] mem_unchanged=true
present: count=2 size=2 keys=[alpha,gamma] store=[alpha=1,gamma=3] walk=[alpha=1,gamma=3] isset_beta=false mem_shrunk=true
again:   count=2 size=2 keys=[alpha,gamma] store=[alpha=1,gamma=3] walk=[alpha=1,gamma=3] mem_unchanged=true
nav:     first='alpha' next='gamma' last='gamma'
drained: count=0 size=0 keys=[] store=[] walk=[] mem=0
post:    count=0 size=0 keys=[] store=[] walk=[] mem=0
reinsert:count=1 size=1 keys=[alpha] store=[alpha=11] walk=[alpha=11]
--- optimizeIteration=true ---
honoured: true
empty:   count=0 size=0 keys=[] store=[] walk=[] mem=0
filled:  count=3 size=3 keys=[alpha,beta,gamma] store=[alpha=1,beta=2,gamma=3] walk=[alpha=1,beta=2,gamma=3]
absent:  count=3 size=3 keys=[alpha,beta,gamma] store=[alpha=1,beta=2,gamma=3] walk=[alpha=1,beta=2,gamma=3] mem_unchanged=true
present: count=2 size=2 keys=[alpha,gamma] store=[alpha=1,gamma=3] walk=[alpha=1,gamma=3] isset_beta=false mem_shrunk=true
again:   count=2 size=2 keys=[alpha,gamma] store=[alpha=1,gamma=3] walk=[alpha=1,gamma=3] mem_unchanged=true
nav:     first='alpha' next='gamma' last='gamma'
drained: count=0 size=0 keys=[] store=[] walk=[] mem=0
post:    count=0 size=0 keys=[] store=[] walk=[] mem=0
reinsert:count=1 size=1 keys=[alpha] store=[alpha=11] walk=[alpha=11]
Done
