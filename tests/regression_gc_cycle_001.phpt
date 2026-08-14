--TEST--
Regression: reference cycles through MIXED values are collectable (get_gc)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Without a get_gc handler the cycle collector cannot see the zval stored in a
// MIXED Judy value, so a cycle Judy -> value -> Judy is uncollectable until
// request shutdown. With get_gc, gc_collect_cycles() reclaims it.
gc_collect_cycles();

function make_cycle(int $type) {
    $j = new Judy($type);
    $holder = new stdClass();
    $holder->j = $j;      // holder references the Judy object
    $key = ($type === Judy::INT_TO_MIXED) ? 0 : "k";
    $j[$key] = $holder;   // ...and back
    // Local $j, $holder go out of scope on return, leaving only the cycle.
}

foreach ([Judy::INT_TO_MIXED, Judy::STRING_TO_MIXED, Judy::STRING_TO_MIXED_HASH,
          Judy::STRING_TO_MIXED_ADAPTIVE] as $type) {
    make_cycle($type);
}

$collected = gc_collect_cycles();
// Each make_cycle leaves one collectable cycle (>= the 4 we created) once the
// Judy side is visible to the GC.
echo $collected >= 4 ? "cycles collected\n" : "leaked ($collected)\n";
?>
--EXPECT--
cycles collected
