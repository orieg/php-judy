--TEST--
Judy BITSET xor() - symmetric difference, identical (empty result)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Symmetric difference
$a = new Judy(Judy::BITSET);
$b = new Judy(Judy::BITSET);
$a[1] = true;
$a[2] = true;
$a[3] = true;
$b[2] = true;
$b[3] = true;
$b[4] = true;

$result = $a->xor($b);
echo "XOR count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k\n";
}

// Identical sets (empty result)
$c = new Judy(Judy::BITSET);
$d = new Judy(Judy::BITSET);
$c[5] = true;
$c[10] = true;
$d[5] = true;
$d[10] = true;

$result2 = $c->xor($d);
echo "Identical count: " . $result2->count() . "\n";

// Completely disjoint
$e = new Judy(Judy::BITSET);
$f = new Judy(Judy::BITSET);
$e[1] = true;
$e[2] = true;
$f[3] = true;
$f[4] = true;

$result3 = $e->xor($f);
echo "Disjoint XOR count: " . $result3->count() . "\n";
foreach ($result3 as $k => $v) {
    echo "  $k\n";
}

// One empty
$g = new Judy(Judy::BITSET);
$h = new Judy(Judy::BITSET);
$g[7] = true;
$result4 = $g->xor($h);
echo "One empty count: " . $result4->count() . "\n";
foreach ($result4 as $k => $v) {
    echo "  $k\n";
}

// Both empty
$i = new Judy(Judy::BITSET);
$j = new Judy(Judy::BITSET);
$result5 = $i->xor($j);
echo "Both empty count: " . $result5->count() . "\n";
?>
--EXPECT--
XOR count: 2
  1
  4
Identical count: 0
Disjoint XOR count: 4
  1
  2
  3
  4
One empty count: 1
  7
Both empty count: 0
