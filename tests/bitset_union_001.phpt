--TEST--
Judy BITSET union() - disjoint, overlapping, and empty sets
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Disjoint sets
$a = new Judy(Judy::BITSET);
$b = new Judy(Judy::BITSET);
$a[1] = true;
$a[3] = true;
$a[5] = true;
$b[2] = true;
$b[4] = true;
$b[6] = true;

$result = $a->union($b);
echo "Disjoint count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k\n";
}

// Overlapping sets
$c = new Judy(Judy::BITSET);
$d = new Judy(Judy::BITSET);
$c[1] = true;
$c[2] = true;
$c[3] = true;
$d[2] = true;
$d[3] = true;
$d[4] = true;

$result2 = $c->union($d);
echo "Overlapping count: " . $result2->count() . "\n";
foreach ($result2 as $k => $v) {
    echo "  $k\n";
}

// Empty set union
$e = new Judy(Judy::BITSET);
$f = new Judy(Judy::BITSET);
$e[10] = true;

$result3 = $e->union($f);
echo "Empty other count: " . $result3->count() . "\n";
foreach ($result3 as $k => $v) {
    echo "  $k\n";
}

$result4 = $f->union($e);
echo "Empty self count: " . $result4->count() . "\n";
foreach ($result4 as $k => $v) {
    echo "  $k\n";
}

// Both empty
$g = new Judy(Judy::BITSET);
$h = new Judy(Judy::BITSET);
$result5 = $g->union($h);
echo "Both empty count: " . $result5->count() . "\n";
?>
--EXPECT--
Disjoint count: 6
  1
  2
  3
  4
  5
  6
Overlapping count: 4
  1
  2
  3
  4
Empty other count: 1
  10
Empty self count: 1
  10
Both empty count: 0
