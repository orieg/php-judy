--TEST--
Regression #162: GC re-entrancy during MIXED teardown must not touch freed zvals
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// judy_free_array_internal() walks the container calling zval_ptr_dtor() on
// every stored value. Dropping a GC-collectable value to a still-positive
// refcount calls gc_possible_root(); once the root buffer fills, PHP runs
// gc_collect_cycles() synchronously *inside* that loop. If the Judy object is
// itself a GC root, the collector calls judy_object_get_gc(), which used to
// re-walk the half-freed container and dereference zvals the loop had already
// efree()d — a use-after-free that surfaced as "zend_mm_heap corrupted".
//
// Three conditions are needed and all three are reproduced below:
//   1. the values are GC-collectable AND shared ($data holds a second
//      reference), so zval_ptr_dtor roots them instead of freeing them;
//   2. there are enough of them to fill the root buffer (default threshold is
//      ~10000), so the collector actually runs inside teardown;
//   3. the Judy object is itself in the root buffer — count() puts it there,
//      as does passing it to any function.
//
// On an unfixed build the first type below aborts the process.

const N = 20000;

function churn(int $type, bool $string_keys): void {
    $data = [];
    for ($i = 0; $i < N; $i++) {
        $data[$string_keys ? "k$i" : $i] = [$i, $i + 1];   // condition 1 + 2
    }
    for ($round = 0; $round < 3; $round++) {
        $j = new Judy($type);
        foreach ($data as $k => $v) {
            $j[$k] = $v;
        }
        if (count($j) !== N) {                            // condition 3
            echo "bad count\n";
            return;
        }
        unset($j);
    }
}

churn(Judy::INT_TO_MIXED, false);
churn(Judy::STRING_TO_MIXED, true);
churn(Judy::STRING_TO_MIXED_HASH, true);
churn(Judy::STRING_TO_MIXED_ADAPTIVE, true);
echo "teardown survived\n";

// The same walk runs from the explicit Judy::free() entry point, on an object
// that is still live and therefore trivially reachable by the collector.
$data = [];
$j = new Judy(Judy::INT_TO_MIXED);
for ($i = 0; $i < N; $i++) {
    $v = [$i, $i + 1];
    $data[$i] = $v;
    $j[$i] = $v;
}
count($j);
$j->free();
echo count($j) === 0 ? "free() survived\n" : "free() left state\n";
unset($j, $data);

// Detaching the container during teardown must not break get_gc for live
// objects: a cycle through a MIXED value still has to be collectable.
$j = new Judy(Judy::INT_TO_MIXED);
$holder = new stdClass();
$holder->j = $j;
$j[0] = $holder;
unset($j, $holder);
echo gc_collect_cycles() >= 1 ? "cycles collected\n" : "cycle leaked\n";
?>
--EXPECT--
teardown survived
free() survived
cycles collected
