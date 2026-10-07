--TEST--
Judy::increment() boundary arithmetic wraps instead of invoking signed-overflow UB
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Signed-overflow fix (plan Step 1.6): increment() computed
 * `old_val + amount` as signed zend_long addition. At PHP_INT_MAX / PHP_INT_MIN
 * that is undefined behaviour in C — the compiler is free to assume it never
 * happens, so a future -O3/-flto build may fold the edge case arbitrarily.
 *
 * Contract decision recorded for Gate 1: the default ruling is WRAP (compute
 * in zend_ulong, reinterpret the bit pattern as zend_long). Rationale: a Judy
 * INT slot is a machine word and cannot hold a float (PHP's own `+=` promotes
 * to float on overflow — impossible here), so the in-type choices are wrap or
 * throw; wrap is also what the code already produced in practice at -O3, and
 * a throw would be a NEW user-visible boundary contract requiring explicit
 * sign-off. If Gate 1 signs off on throw instead, this test's expectations
 * change deliberately (Rule: gate reversals are recorded, not silent).
 *
 * On pre-fix builds this test usually already passed (x86/ARM two's-complement
 * wrap at -O3) — it is therefore a characterization of the boundary behavior
 * plus the regression net for the UB removal, not a red-run detector. */
foreach ([Judy::INT_TO_INT, Judy::STRING_TO_INT] as $type) {
    $j = new Judy($type);
    $k = $type === Judy::INT_TO_INT ? 1 : "k";

    $j[$k] = PHP_INT_MAX;
    var_dump($j->increment($k));        // INT_MAX + 1 → INT_MIN (wrap)
    var_dump($j[$k]);
    var_dump($j->increment($k, -1));    // INT_MIN - 1 → INT_MAX (wrap)
    var_dump($j[$k]);

    $j[$k] = PHP_INT_MIN;
    var_dump($j->increment($k, -2));    // INT_MIN - 2 → INT_MAX - 1
    var_dump($j[$k]);

    // Ordinary arithmetic is untouched.
    $j[$k] = 10;
    var_dump($j->increment($k, 5));
    var_dump($j[$k]);
}

echo "Done\n";
?>
--EXPECT--
int(-9223372036854775808)
int(-9223372036854775808)
int(9223372036854775807)
int(9223372036854775807)
int(9223372036854775806)
int(9223372036854775806)
int(15)
int(15)
int(-9223372036854775808)
int(-9223372036854775808)
int(9223372036854775807)
int(9223372036854775807)
int(9223372036854775806)
int(9223372036854775806)
int(15)
int(15)
Done
