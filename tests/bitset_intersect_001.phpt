--TEST--
Judy BITSET intersect() - overlap, disjoint, subset, and empty
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Overlapping sets
$a = new Judy(Judy::BITSET);
$b = new Judy(Judy::BITSET);
$a[1] = true;
$a[2] = true;
$a[3] = true;
$b[2] = true;
$b[3] = true;
$b[4] = true;

$result = $a->intersect($b);
echo "Overlap count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k\n";
}

// Disjoint sets
$c = new Judy(Judy::BITSET);
$d = new Judy(Judy::BITSET);
$c[1] = true;
$c[3] = true;
$d[2] = true;
$d[4] = true;

$result2 = $c->intersect($d);
echo "Disjoint count: " . $result2->count() . "\n";

// Subset (a is subset of b)
$e = new Judy(Judy::BITSET);
$f = new Judy(Judy::BITSET);
$e[2] = true;
$e[3] = true;
$f[1] = true;
$f[2] = true;
$f[3] = true;
$f[4] = true;

$result3 = $e->intersect($f);
echo "Subset count: " . $result3->count() . "\n";
foreach ($result3 as $k => $v) {
    echo "  $k\n";
}

// Empty set
$g = new Judy(Judy::BITSET);
$h = new Judy(Judy::BITSET);
$g[1] = true;
$result4 = $g->intersect($h);
echo "Empty other count: " . $result4->count() . "\n";

// Both empty
$i = new Judy(Judy::BITSET);
$j = new Judy(Judy::BITSET);
$result5 = $i->intersect($j);
echo "Both empty count: " . $result5->count() . "\n";
?>
--EXPECT--
Overlap count: 2
  2
  3
Disjoint count: 0
Subset count: 2
  2
  3
Empty other count: 0
Both empty count: 0
