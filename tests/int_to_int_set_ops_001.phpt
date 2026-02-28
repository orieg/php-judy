--TEST--
Judy INT_TO_INT union() - disjoint, overlapping (left-wins), and empty
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Disjoint sets
$a = new Judy(Judy::INT_TO_INT);
$b = new Judy(Judy::INT_TO_INT);
$a[1] = 10;
$a[3] = 30;
$b[2] = 20;
$b[4] = 40;

$result = $a->union($b);
echo "Disjoint count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k => $v\n";
}

// Overlapping - left-wins: self's values should take priority
$c = new Judy(Judy::INT_TO_INT);
$d = new Judy(Judy::INT_TO_INT);
$c[1] = 100;
$c[2] = 200;
$c[3] = 300;
$d[2] = 999;
$d[3] = 888;
$d[4] = 400;

$result2 = $c->union($d);
echo "Overlapping count: " . $result2->count() . "\n";
foreach ($result2 as $k => $v) {
    echo "  $k => $v\n";
}

// Empty set union
$e = new Judy(Judy::INT_TO_INT);
$f = new Judy(Judy::INT_TO_INT);
$e[10] = 100;

$result3 = $e->union($f);
echo "Empty other count: " . $result3->count() . "\n";
foreach ($result3 as $k => $v) {
    echo "  $k => $v\n";
}

$result4 = $f->union($e);
echo "Empty self count: " . $result4->count() . "\n";
foreach ($result4 as $k => $v) {
    echo "  $k => $v\n";
}

// Both empty
$g = new Judy(Judy::INT_TO_INT);
$h = new Judy(Judy::INT_TO_INT);
$result5 = $g->union($h);
echo "Both empty count: " . $result5->count() . "\n";
?>
--EXPECT--
Disjoint count: 4
  1 => 10
  2 => 20
  3 => 30
  4 => 40
Overlapping count: 4
  1 => 100
  2 => 200
  3 => 300
  4 => 400
Empty other count: 1
  10 => 100
Empty self count: 1
  10 => 100
Both empty count: 0
