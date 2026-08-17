--TEST--
Judy keys()/values()/toArray() range arguments for string-keyed types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/*
 * String-keyed types are in scope for the range arguments: every one of them
 * iterates its JudySL key index in lexicographic order, so the same bounded
 * single traversal applies. Bounds must be strings and are compared with
 * strcmp(), exactly as slice() compares them.
 */

$int_types = [
    'STRING_TO_INT'          => Judy::STRING_TO_INT,
    'STRING_TO_INT_HASH'     => Judy::STRING_TO_INT_HASH,
    'STRING_TO_INT_ADAPTIVE' => Judy::STRING_TO_INT_ADAPTIVE,
];
$mixed_types = [
    'STRING_TO_MIXED'          => Judy::STRING_TO_MIXED,
    'STRING_TO_MIXED_HASH'     => Judy::STRING_TO_MIXED_HASH,
    'STRING_TO_MIXED_ADAPTIVE' => Judy::STRING_TO_MIXED_ADAPTIVE,
];

/* Deliberately mixes keys at or below the 7-byte SSO threshold with longer
   ones, so the adaptive types exercise both their JudyL and JudyHS stores. */
$fruit = ["apple", "apricot", "banana", "blackcurrant", "cherry", "date"];

foreach ($int_types + $mixed_types as $name => $type) {
    $j = new Judy($type);
    foreach ($fruit as $i => $key) {
        $j[$key] = isset($int_types[$name]) ? $i : "v$i";
    }

    echo "== $name\n";
    echo "  keys(b,c):        ", json_encode($j->keys("b", "c")), "\n";
    echo "  keys(apricot,cherry): ", json_encode($j->keys("apricot", "cherry")), "\n";
    echo "  keys(b):          ", json_encode($j->keys("b")), "\n";
    echo "  keys(null,b):     ", json_encode($j->keys(null, "b")), "\n";
    echo "  keys():           ", json_encode($j->keys()), "\n";
    /* An upper bound is a bound, not a prefix filter: "blackcurrant" sorts
       after "bl", so bounding at "bl" excludes it and "bm" includes it. */
    echo "  keys(bb,bl):      ", json_encode($j->keys("bb", "bl")), "\n";
    echo "  keys(bb,bm):      ", json_encode($j->keys("bb", "bm")), "\n";
    echo "  keys(zz,zzz):     ", json_encode($j->keys("zz", "zzz")), "\n";
    echo "  keys(c,b):        ", json_encode($j->keys("c", "b")), "\n";
    echo "  values(b,c):      ", json_encode($j->values("b", "c")), "\n";
    echo "  toArray(b,c):     ", json_encode($j->toArray("b", "c")), "\n";
    echo "  toArray(c,b):     ", json_encode($j->toArray("c", "b")), "\n";

    /* A bounded read must equal the same range copied then read whole. */
    $agree = true;
    foreach ([["b", "c"], ["apple", "apple"], ["bb", "bl"], ["c", "b"], ["", "zzz"]] as [$lo, $hi]) {
        $agree = $agree
            && $j->keys($lo, $hi) === $j->slice($lo, $hi)->keys()
            && $j->toArray($lo, $hi) === $j->slice($lo, $hi)->toArray();
    }
    echo "  agrees with slice: ", $agree ? "yes" : "no", "\n";

    /* Non-string bounds are a TypeError, as they are for slice(). */
    foreach ([[1, 2], ["a", 2], [1.5, "z"], [true, "z"]] as [$lo, $hi]) {
        try {
            $j->keys($lo, $hi);
            echo "  no throw\n";
        } catch (TypeError $e) {
            echo "  TypeError: ", $e->getMessage(), "\n";
        }
    }

    $empty = new Judy($type);
    echo "  empty source:     ",
        json_encode($empty->keys("a", "z")), " ",
        json_encode($empty->values("a", "z")), " ",
        json_encode($empty->toArray("a", "z")), "\n";
}
?>
--EXPECTF--
== STRING_TO_INT
  keys(b,c):        ["banana","blackcurrant"]
  keys(apricot,cherry): ["apricot","banana","blackcurrant","cherry"]
  keys(b):          ["banana","blackcurrant","cherry","date"]
  keys(null,b):     ["apple","apricot"]
  keys():           ["apple","apricot","banana","blackcurrant","cherry","date"]
  keys(bb,bl):      []
  keys(bb,bm):      ["blackcurrant"]
  keys(zz,zzz):     []
  keys(c,b):        []
  values(b,c):      [2,3]
  toArray(b,c):     {"banana":2,"blackcurrant":3}
  toArray(c,b):     []
  agrees with slice: yes
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  empty source:     [] [] []
== STRING_TO_INT_HASH
  keys(b,c):        ["banana","blackcurrant"]
  keys(apricot,cherry): ["apricot","banana","blackcurrant","cherry"]
  keys(b):          ["banana","blackcurrant","cherry","date"]
  keys(null,b):     ["apple","apricot"]
  keys():           ["apple","apricot","banana","blackcurrant","cherry","date"]
  keys(bb,bl):      []
  keys(bb,bm):      ["blackcurrant"]
  keys(zz,zzz):     []
  keys(c,b):        []
  values(b,c):      [2,3]
  toArray(b,c):     {"banana":2,"blackcurrant":3}
  toArray(c,b):     []
  agrees with slice: yes
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  empty source:     [] [] []
== STRING_TO_INT_ADAPTIVE
  keys(b,c):        ["banana","blackcurrant"]
  keys(apricot,cherry): ["apricot","banana","blackcurrant","cherry"]
  keys(b):          ["banana","blackcurrant","cherry","date"]
  keys(null,b):     ["apple","apricot"]
  keys():           ["apple","apricot","banana","blackcurrant","cherry","date"]
  keys(bb,bl):      []
  keys(bb,bm):      ["blackcurrant"]
  keys(zz,zzz):     []
  keys(c,b):        []
  values(b,c):      [2,3]
  toArray(b,c):     {"banana":2,"blackcurrant":3}
  toArray(c,b):     []
  agrees with slice: yes
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  empty source:     [] [] []
== STRING_TO_MIXED
  keys(b,c):        ["banana","blackcurrant"]
  keys(apricot,cherry): ["apricot","banana","blackcurrant","cherry"]
  keys(b):          ["banana","blackcurrant","cherry","date"]
  keys(null,b):     ["apple","apricot"]
  keys():           ["apple","apricot","banana","blackcurrant","cherry","date"]
  keys(bb,bl):      []
  keys(bb,bm):      ["blackcurrant"]
  keys(zz,zzz):     []
  keys(c,b):        []
  values(b,c):      ["v2","v3"]
  toArray(b,c):     {"banana":"v2","blackcurrant":"v3"}
  toArray(c,b):     []
  agrees with slice: yes
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  empty source:     [] [] []
== STRING_TO_MIXED_HASH
  keys(b,c):        ["banana","blackcurrant"]
  keys(apricot,cherry): ["apricot","banana","blackcurrant","cherry"]
  keys(b):          ["banana","blackcurrant","cherry","date"]
  keys(null,b):     ["apple","apricot"]
  keys():           ["apple","apricot","banana","blackcurrant","cherry","date"]
  keys(bb,bl):      []
  keys(bb,bm):      ["blackcurrant"]
  keys(zz,zzz):     []
  keys(c,b):        []
  values(b,c):      ["v2","v3"]
  toArray(b,c):     {"banana":"v2","blackcurrant":"v3"}
  toArray(c,b):     []
  agrees with slice: yes
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  empty source:     [] [] []
== STRING_TO_MIXED_ADAPTIVE
  keys(b,c):        ["banana","blackcurrant"]
  keys(apricot,cherry): ["apricot","banana","blackcurrant","cherry"]
  keys(b):          ["banana","blackcurrant","cherry","date"]
  keys(null,b):     ["apple","apricot"]
  keys():           ["apple","apricot","banana","blackcurrant","cherry","date"]
  keys(bb,bl):      []
  keys(bb,bm):      ["blackcurrant"]
  keys(zz,zzz):     []
  keys(c,b):        []
  values(b,c):      ["v2","v3"]
  toArray(b,c):     {"banana":"v2","blackcurrant":"v3"}
  toArray(c,b):     []
  agrees with slice: yes
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  TypeError: Judy::keys() expects string arguments for string-keyed arrays
  empty source:     [] [] []
