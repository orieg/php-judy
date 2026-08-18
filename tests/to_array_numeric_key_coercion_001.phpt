--TEST--
Judy toArray() coerces integer-looking string keys; keys() does not
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/*
 * toArray() returns a PHP array, and a PHP array cannot hold the string key
 * "42" — the engine coerces a canonical decimal integer in PHP_INT range to an
 * int. keys() has no such constraint and returns every key as a string.
 *
 * The asymmetry is silent until a numeric key appears, and then it bites on
 * round-trip: a key taken from toArray() and fed back as an offset arrives as
 * an int, which a string-keyed Judy rejects. This test pins both halves so the
 * documented contract cannot drift.
 */

$cases = [
    /* coerced to int */
    '42', '-7', '0', '9223372036854775807',
    /* left as string */
    '07', '-0', ' 42', '42 ', '4.0', '', '0x1A', '9223372036854775808',
    'user.3',
];

foreach ([Judy::STRING_TO_INT, Judy::STRING_TO_MIXED] as $type) {
    $j = new Judy($type);
    foreach ($cases as $i => $k) {
        $j[$k] = $i;
    }

    echo "== type $type\n";

    $arr = $j->toArray();
    $ints = [];
    foreach ($arr as $k => $_) {
        if (is_int($k)) {
            $ints[] = $k;
        }
    }
    sort($ints);
    echo "  toArray() int keys: ", implode(', ', $ints), "\n";

    /* keys() never coerces */
    $allString = true;
    foreach ($j->keys() as $k) {
        $allString = $allString && is_string($k);
    }
    echo "  keys() all string:  ", $allString ? 'yes' : 'no', "\n";

    /* Same set either way, once spelling is normalised. */
    $viaToArray = array_map('strval', array_keys($arr));
    $viaKeys = $j->keys();
    sort($viaToArray);
    sort($viaKeys);
    echo "  same key set:       ", $viaToArray === $viaKeys ? 'yes' : 'no', "\n";

    /* The round-trip trap: a coerced key fed back as an offset throws. */
    try {
        unset($j[42]);
        echo "  unset(int) threw:   no\n";
    } catch (TypeError $e) {
        echo "  unset(int) threw:   yes\n";
    }

    /* Casting back to string is the documented fix. */
    $before = $j->count();
    foreach ($arr as $k => $_) {
        unset($j[(string)$k]);
    }
    printf("  (string) cast unset: %d -> %d\n", $before, $j->count());
}
?>
--EXPECT--
== type 4
  toArray() int keys: -7, 0, 42, 9223372036854775807
  keys() all string:  yes
  same key set:       yes
  unset(int) threw:   yes
  (string) cast unset: 13 -> 0
== type 5
  toArray() int keys: -7, 0, 42, 9223372036854775807
  keys() all string:  yes
  same key set:       yes
  unset(int) threw:   yes
  (string) cast unset: 13 -> 0
