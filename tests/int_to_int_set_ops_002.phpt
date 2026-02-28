--TEST--
Judy INT_TO_INT intersect() - overlapping, disjoint, and empty
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Overlapping - values from self (left-wins)
$a = new Judy(Judy::INT_TO_INT);
$b = new Judy(Judy::INT_TO_INT);
$a[1] = 10;
$a[2] = 20;
$a[3] = 30;
$b[2] = 999;
$b[3] = 888;
$b[4] = 40;

$result = $a->intersect($b);
echo "Overlapping count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k => $v\n";
}

// Reversed - self's values still win
$result2 = $b->intersect($a);
echo "Reversed count: " . $result2->count() . "\n";
foreach ($result2 as $k => $v) {
    echo "  $k => $v\n";
}

// Disjoint
$c = new Judy(Judy::INT_TO_INT);
$d = new Judy(Judy::INT_TO_INT);
$c[1] = 10;
$c[3] = 30;
$d[2] = 20;
$d[4] = 40;

$result3 = $c->intersect($d);
echo "Disjoint count: " . $result3->count() . "\n";

// Empty
$e = new Judy(Judy::INT_TO_INT);
$f = new Judy(Judy::INT_TO_INT);
$e[1] = 10;

$result4 = $e->intersect($f);
echo "Empty other count: " . $result4->count() . "\n";

$result5 = $f->intersect($e);
echo "Empty self count: " . $result5->count() . "\n";
?>
--EXPECT--
Overlapping count: 2
  2 => 20
  3 => 30
Reversed count: 2
  2 => 999
  3 => 888
Disjoint count: 0
Empty other count: 0
Empty self count: 0
