--TEST--
Judy size() counts a key range on string-keyed types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/*
 * size($start, $end) used to accept string bounds on a string-keyed array,
 * ignore them, and return the whole-array count — a plausible-looking wrong
 * answer. It now counts the same inclusive [$start, $end] range that
 * keys()/values()/toArray() read, so `size($lo, $hi)` and
 * `count($j->keys($lo, $hi))` agree by construction; the point of the former
 * is that it never builds the array.
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

/* Mixes keys at or below the 7-byte SSO threshold with longer ones, so the
   adaptive types count across both their JudyL and JudyHS stores. */
$fruit = ["apple", "apricot", "banana", "blackcurrant", "cherry", "date"];

foreach ($int_types + $mixed_types as $name => $type) {
    $j = new Judy($type);
    foreach ($fruit as $i => $key) {
        $j[$key] = isset($int_types[$name]) ? $i : "v$i";
    }

    echo "== $name\n";
    echo "  size():           ", $j->size(), "\n";
    echo "  size(b,c):        ", $j->size("b", "c"), "\n";
    echo "  size(apricot,cherry): ", $j->size("apricot", "cherry"), "\n";
    echo "  size(b):          ", $j->size("b"), "\n";
    echo "  size(null,b):     ", $j->size(null, "b"), "\n";
    echo "  size(apple,apple):", $j->size("apple", "apple"), "\n";
    /* An upper bound is a bound, not a prefix filter: "blackcurrant" sorts
       after "bl", so bounding at "bl" excludes it and "bm" includes it. */
    echo "  size(bb,bl):      ", $j->size("bb", "bl"), "\n";
    echo "  size(bb,bm):      ", $j->size("bb", "bm"), "\n";
    echo "  size(zz,zzz):     ", $j->size("zz", "zzz"), "\n";
    /* An inverted range yields nothing rather than erroring. */
    echo "  size(c,b):        ", $j->size("c", "b"), "\n";

    /* The contract that matters: counting a range agrees with reading it. */
    $agree = true;
    foreach ([["b", "c"], ["apple", "apple"], ["bb", "bl"], ["c", "b"], ["", "zzz"],
              [null, "b"], ["b", null], [null, null]] as [$lo, $hi]) {
        $agree = $agree && $j->size($lo, $hi) === count($j->keys($lo, $hi));
    }
    echo "  agrees with keys: ", $agree ? "yes" : "no", "\n";

    /* Non-string bounds are a TypeError, as they are for keys() and slice(). */
    foreach ([[1, 2], ["a", 2], [1.5, "z"], [true, "z"]] as [$lo, $hi]) {
        try {
            $j->size($lo, $hi);
            echo "  no throw\n";
        } catch (TypeError $e) {
            echo "  TypeError: ", $e->getMessage(), "\n";
        }
    }

    $empty = new Judy($type);
    echo "  empty source:     ", $empty->size(), " ", $empty->size("a", "z"), "\n";

    /* Counting inside a foreach must not disturb the walk: the iterator holds
       its own key buffer. */
    $seen = 0;
    foreach ($j as $key => $_) {
        $seen += $j->size($key, $key);
    }
    echo "  counted in loop:  ", $seen, "\n";
}

/* The reported use case: "how many classes live under this namespace?", with
   no way to answer it before except by materialising the keys. */
$sym = new Judy(Judy::STRING_TO_MIXED);
foreach (["App\\Domain\\Order", "App\\Domain\\Cart", "App\\DomainEvents\\X", "App\\Http\\Y"] as $class) {
    $sym[$class] = true;
}
echo "== namespace prefix\n";
echo "  total:            ", count($sym), "\n";
echo "  App\\Domain\\:      ", $sym->size("App\\Domain\\", "App\\Domain]"), "\n";
echo "  matches keys:     ", count($sym->keys("App\\Domain\\", "App\\Domain]")), "\n";

/* Integer-keyed control: the bounds still mean what they did, and the two
   unbounded spellings (no arguments, explicit nulls, explicit 0/-1) still
   agree. */
$i = Judy::fromArray(Judy::INT_TO_INT, [1 => 10, 5 => 50, 10 => 100, 1000 => 1000]);
echo "== INT_TO_INT\n";
echo "  size():           ", $i->size(), "\n";
echo "  size(null,null):  ", $i->size(null, null), "\n";
echo "  size(0,-1):       ", $i->size(0, -1), "\n";
echo "  size(0,100):      ", $i->size(0, 100), "\n";
echo "  size(null,100):   ", $i->size(null, 100), "\n";
echo "  size(100,0):      ", $i->size(100, 0), "\n";
echo "  matches popCount: ", $i->size(0, 100) === $i->populationCount(0, 100) ? "yes" : "no", "\n";

/* size() and keys() now parse their bounds in the same place, so they agree on
   integer-keyed arrays too — whatever the coercion rules are, one method can
   no longer read a bound differently from the other. */
$agree = true;
foreach ([[0, 100], ["5", 100], [null, 100], [0, null], [100, 0], [0, -1]] as [$lo, $hi]) {
    $agree = $agree && $i->size($lo, $hi) === count($i->keys($lo, $hi));
}
echo "  agrees with keys: ", $agree ? "yes" : "no", "\n";

$b = new Judy(Judy::BITSET);
foreach ([0, 1, 2, 50, 99] as $bit) {
    $b[$bit] = 1;
}
echo "== BITSET\n";
echo "  size():           ", $b->size(), "\n";
echo "  size(0,49):       ", $b->size(0, 49), "\n";
echo "  size(50,99):      ", $b->size(50, 99), "\n";

/* The bounds are unsigned machine words, and that is load-bearing rather than
   incidental: -1 has to keep reading as the maximum bound (which is what makes
   the no-argument default and the explicit size(0, -1) the same query), and
   negative keys sort above PHP_INT_MAX, so a range over them must not be
   treated as inverted. A signed comparison here would break both. */
$n = new Judy(Judy::INT_TO_INT);
$n[1] = 1;
$n[5] = 5;
$n[-2] = -2;
$n[-1] = -1;
echo "== negative keys\n";
echo "  count:            ", count($n), "\n";
echo "  size(0,-1):       ", $n->size(0, -1), "\n";
echo "  == count():       ", $n->size(0, -1) === count($n) ? "yes" : "no", "\n";
echo "  == size():        ", $n->size(0, -1) === $n->size() ? "yes" : "no", "\n";
echo "  size(-2,-1):      ", $n->size(-2, -1), "\n";
echo "  size(0,PHP_INT_MAX): ", $n->size(0, PHP_INT_MAX), "\n";
echo "  size(PHP_INT_MIN,-1):", $n->size(PHP_INT_MIN, -1), "\n";
echo "  == popCount:      ", $n->size(-2, -1) === $n->populationCount(-2, -1) ? "yes" : "no", "\n";
?>
--EXPECT--
== STRING_TO_INT
  size():           6
  size(b,c):        2
  size(apricot,cherry): 4
  size(b):          4
  size(null,b):     2
  size(apple,apple):1
  size(bb,bl):      0
  size(bb,bm):      1
  size(zz,zzz):     0
  size(c,b):        0
  agrees with keys: yes
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  empty source:     0 0
  counted in loop:  6
== STRING_TO_INT_HASH
  size():           6
  size(b,c):        2
  size(apricot,cherry): 4
  size(b):          4
  size(null,b):     2
  size(apple,apple):1
  size(bb,bl):      0
  size(bb,bm):      1
  size(zz,zzz):     0
  size(c,b):        0
  agrees with keys: yes
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  empty source:     0 0
  counted in loop:  6
== STRING_TO_INT_ADAPTIVE
  size():           6
  size(b,c):        2
  size(apricot,cherry): 4
  size(b):          4
  size(null,b):     2
  size(apple,apple):1
  size(bb,bl):      0
  size(bb,bm):      1
  size(zz,zzz):     0
  size(c,b):        0
  agrees with keys: yes
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  empty source:     0 0
  counted in loop:  6
== STRING_TO_MIXED
  size():           6
  size(b,c):        2
  size(apricot,cherry): 4
  size(b):          4
  size(null,b):     2
  size(apple,apple):1
  size(bb,bl):      0
  size(bb,bm):      1
  size(zz,zzz):     0
  size(c,b):        0
  agrees with keys: yes
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  empty source:     0 0
  counted in loop:  6
== STRING_TO_MIXED_HASH
  size():           6
  size(b,c):        2
  size(apricot,cherry): 4
  size(b):          4
  size(null,b):     2
  size(apple,apple):1
  size(bb,bl):      0
  size(bb,bm):      1
  size(zz,zzz):     0
  size(c,b):        0
  agrees with keys: yes
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  empty source:     0 0
  counted in loop:  6
== STRING_TO_MIXED_ADAPTIVE
  size():           6
  size(b,c):        2
  size(apricot,cherry): 4
  size(b):          4
  size(null,b):     2
  size(apple,apple):1
  size(bb,bl):      0
  size(bb,bm):      1
  size(zz,zzz):     0
  size(c,b):        0
  agrees with keys: yes
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  TypeError: Judy::size() expects string arguments for string-keyed arrays
  empty source:     0 0
  counted in loop:  6
== namespace prefix
  total:            4
  App\Domain\:      2
  matches keys:     2
== INT_TO_INT
  size():           4
  size(null,null):  4
  size(0,-1):       4
  size(0,100):      3
  size(null,100):   3
  size(100,0):      0
  matches popCount: yes
  agrees with keys: yes
== BITSET
  size():           5
  size(0,49):       3
  size(50,99):      2
== negative keys
  count:            4
  size(0,-1):       4
  == count():       yes
  == size():        yes
  size(-2,-1):      2
  size(0,PHP_INT_MAX): 2
  size(PHP_INT_MIN,-1):2
  == popCount:      yes
