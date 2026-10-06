--TEST--
judy_type() negatives: TypeError on non-Judy values, int 0 for an unconstructed instance
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Three arginfo TypeErrors (int, string, foreign object) with the exact
 * engine-format messages, plus the probed cell the old plan text got wrong:
 * judy_type() on a Reflection newInstanceWithoutConstructor() instance — the
 * constructor never ran so the type field is unset — returns int 0, it does
 * NOT throw. Verified stable: 500 consecutive unconstructed instances all
 * reported 0. A control pins that a real instance reports its type enum. */

function t(string $label, callable $fn): void {
    try {
        $r = $fn();
        echo "$label: " . var_export($r, true) . ' (' . gettype($r) . ")\n";
    } catch (\Throwable $e) {
        echo "$label: " . get_class($e) . ' | ' . $e->getMessage() . "\n";
    }
}

t('judy_type(123)', fn() => judy_type(123));
t('judy_type("x")', fn() => judy_type('x'));
t('judy_type(stdClass)', fn() => judy_type(new stdClass()));

$nc = (new ReflectionClass('Judy'))->newInstanceWithoutConstructor();
t('judy_type(unconstructed)', fn() => judy_type($nc));

t('judy_type(INT_TO_INT instance)', fn() => judy_type(new Judy(Judy::INT_TO_INT)));
t('judy_type(BITSET instance)', fn() => judy_type(new Judy(Judy::BITSET)));

echo "done\n";
?>
--EXPECT--
judy_type(123): TypeError | judy_type(): Argument #1 ($array) must be of type Judy, int given
judy_type("x"): TypeError | judy_type(): Argument #1 ($array) must be of type Judy, string given
judy_type(stdClass): TypeError | judy_type(): Argument #1 ($array) must be of type Judy, stdClass given
judy_type(unconstructed): 0 (integer)
judy_type(INT_TO_INT instance): 2 (integer)
judy_type(BITSET instance): 1 (integer)
done
