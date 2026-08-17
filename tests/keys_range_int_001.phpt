--TEST--
Judy keys()/values()/toArray() range arguments for integer-keyed types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
function show(string $label, array $a): void
{
    echo $label, ": ", json_encode($a), "\n";
}

/* ── BITSET: the index is both key and value ──────────────────────── */

$b = new Judy(Judy::BITSET);
foreach ([1, 5, 10, 15, 20, 100] as $k) {
    $b[$k] = true;
}
show("bitset keys(5,15)",     $b->keys(5, 15));
show("bitset values(5,15)",   $b->values(5, 15));
show("bitset toArray(5,15)",  $b->toArray(5, 15));
show("bitset keys(5,5)",      $b->keys(5, 5));
show("bitset keys(6,9)",      $b->keys(6, 9));      // range inside a gap
show("bitset keys(200,300)",  $b->keys(200, 300));  // past the last key
show("bitset keys(15,5)",     $b->keys(15, 5));     // inverted
show("bitset keys(5)",        $b->keys(5));         // unbounded end
show("bitset keys(null,10)",  $b->keys(null, 10));  // unbounded start
show("bitset keys()",         $b->keys());
show("bitset keys(null,null)", $b->keys(null, null));

/* ── INT_TO_INT ───────────────────────────────────────────────────── */

$i = new Judy(Judy::INT_TO_INT);
foreach ([1 => 10, 5 => 50, 10 => 100, 15 => 150] as $k => $v) {
    $i[$k] = $v;
}
show("int keys(5,10)",     $i->keys(5, 10));
show("int values(5,10)",   $i->values(5, 10));
show("int toArray(5,10)",  $i->toArray(5, 10));
show("int toArray(10,5)",  $i->toArray(10, 5));
show("int values(6)",      $i->values(6));

/* ── INT_TO_MIXED ─────────────────────────────────────────────────── */

$m = new Judy(Judy::INT_TO_MIXED);
$m[1] = "one";
$m[5] = [1, 2];
$m[10] = null;
$m[15] = 3.5;
show("mixed keys(5,10)",    $m->keys(5, 10));
show("mixed values(5,10)",  $m->values(5, 10));
show("mixed toArray(5,10)", $m->toArray(5, 10));
show("mixed toArray(2,4)",  $m->toArray(2, 4));

/* ── INT_TO_PACKED ────────────────────────────────────────────────── */

$p = new Judy(Judy::INT_TO_PACKED);
$p[1] = "one";
$p[5] = 50;
$p[10] = true;
$p[15] = 3.5;
show("packed keys(5,10)",    $p->keys(5, 10));
show("packed values(5,10)",  $p->values(5, 10));
show("packed toArray(5,10)", $p->toArray(5, 10));
show("packed toArray(11,14)", $p->toArray(11, 14));

/* ── Empty source ─────────────────────────────────────────────────── */

foreach ([Judy::BITSET, Judy::INT_TO_INT, Judy::INT_TO_MIXED, Judy::INT_TO_PACKED] as $type) {
    $e = new Judy($type);
    printf(
        "empty type %d: %s %s %s\n",
        $type,
        json_encode($e->keys(0, 100)),
        json_encode($e->values(0, 100)),
        json_encode($e->toArray(0, 100))
    );
}

/* ── A bounded read agrees with slice() over the same range ───────── */

foreach ([[5, 15], [0, 0], [6, 9], [15, 5], [-1, -1]] as [$lo, $hi]) {
    $same = $b->keys($lo, $hi) === $b->slice($lo, $hi)->keys()
        && $i->toArray($lo, $hi) === $i->slice($lo, $hi)->toArray()
        && $m->toArray($lo, $hi) === $m->slice($lo, $hi)->toArray()
        && $p->toArray($lo, $hi) === $p->slice($lo, $hi)->toArray();
    printf("slice agreement [%d,%d]: %s\n", $lo, $hi, $same ? "yes" : "no");
}

/* ── Bounds are Word_t, so -1 is the maximum bound, as in size() ──── */

$w = new Judy(Judy::INT_TO_INT);
$w[1] = 10;
$w[PHP_INT_MAX] = 20;
show("word keys(0,-1)",   $w->keys(0, -1));    // 0 .. Word_t max: everything
show("word keys(0,100)",  $w->keys(0, 100));
show("word keys(2,-1)",   $w->keys(2, -1));
printf("word size(0,-1) agrees: %s\n", count($w->keys(0, -1)) === $w->size(0, -1) ? "yes" : "no");

/* Integer-keyed bounds are coerced like slice()'s, so numeric strings work. */
show("coerced keys('5','10')", $i->keys("5", "10"));
?>
--EXPECT--
bitset keys(5,15): [5,10,15]
bitset values(5,15): [5,10,15]
bitset toArray(5,15): [5,10,15]
bitset keys(5,5): [5]
bitset keys(6,9): []
bitset keys(200,300): []
bitset keys(15,5): []
bitset keys(5): [5,10,15,20,100]
bitset keys(null,10): [1,5,10]
bitset keys(): [1,5,10,15,20,100]
bitset keys(null,null): [1,5,10,15,20,100]
int keys(5,10): [5,10]
int values(5,10): [50,100]
int toArray(5,10): {"5":50,"10":100}
int toArray(10,5): []
int values(6): [100,150]
mixed keys(5,10): [5,10]
mixed values(5,10): [[1,2],null]
mixed toArray(5,10): {"5":[1,2],"10":null}
mixed toArray(2,4): []
packed keys(5,10): [5,10]
packed values(5,10): [50,true]
packed toArray(5,10): {"5":50,"10":true}
packed toArray(11,14): []
empty type 1: [] [] []
empty type 2: [] [] []
empty type 3: [] [] []
empty type 6: [] [] []
slice agreement [5,15]: yes
slice agreement [0,0]: yes
slice agreement [6,9]: yes
slice agreement [15,5]: yes
slice agreement [-1,-1]: yes
word keys(0,-1): [1,9223372036854775807]
word keys(0,100): [1]
word keys(2,-1): [9223372036854775807]
word size(0,-1) agrees: yes
coerced keys('5','10'): [5,10]
