--TEST--
Judy BITSET diff() - difference, no overlap, and empty
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Standard difference
$a = new Judy(Judy::BITSET);
$b = new Judy(Judy::BITSET);
$a[1] = true;
$a[2] = true;
$a[3] = true;
$a[4] = true;
$b[2] = true;
$b[4] = true;

$result = $a->diff($b);
echo "Diff count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k\n";
}

// No overlap
$c = new Judy(Judy::BITSET);
$d = new Judy(Judy::BITSET);
$c[1] = true;
$c[3] = true;
$d[2] = true;
$d[4] = true;

$result2 = $c->diff($d);
echo "No overlap count: " . $result2->count() . "\n";
foreach ($result2 as $k => $v) {
    echo "  $k\n";
}

// Self is subset of other (empty result)
$e = new Judy(Judy::BITSET);
$f = new Judy(Judy::BITSET);
$e[2] = true;
$e[3] = true;
$f[1] = true;
$f[2] = true;
$f[3] = true;
$f[4] = true;

$result3 = $e->diff($f);
echo "Subset diff count: " . $result3->count() . "\n";

// Empty other (all kept)
$g = new Judy(Judy::BITSET);
$h = new Judy(Judy::BITSET);
$g[5] = true;
$g[10] = true;
$result4 = $g->diff($h);
echo "Empty other count: " . $result4->count() . "\n";
foreach ($result4 as $k => $v) {
    echo "  $k\n";
}

// Both empty
$i = new Judy(Judy::BITSET);
$j = new Judy(Judy::BITSET);
$result5 = $i->diff($j);
echo "Both empty count: " . $result5->count() . "\n";
?>
--EXPECT--
Diff count: 2
  1
  3
No overlap count: 2
  1
  3
Subset diff count: 0
Empty other count: 2
  5
  10
Both empty count: 0
