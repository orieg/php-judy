--TEST--
Judy INT_TO_INT xor() - overlapping, disjoint, and empty
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Overlapping - keep only entries present in exactly one array
$a = new Judy(Judy::INT_TO_INT);
$b = new Judy(Judy::INT_TO_INT);
$a[1] = 10;
$a[2] = 20;
$a[3] = 30;
$b[2] = 999;
$b[3] = 888;
$b[4] = 40;

$result = $a->xor($b);
echo "Xor count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k => $v\n";
}

// Reversed - same keys, but values come from whichever array owns them
$result2 = $b->xor($a);
echo "Reversed count: " . $result2->count() . "\n";
foreach ($result2 as $k => $v) {
    echo "  $k => $v\n";
}

// Disjoint - xor keeps everything
$c = new Judy(Judy::INT_TO_INT);
$d = new Judy(Judy::INT_TO_INT);
$c[1] = 10;
$c[3] = 30;
$d[2] = 20;
$d[4] = 40;

$result3 = $c->xor($d);
echo "Disjoint count: " . $result3->count() . "\n";
foreach ($result3 as $k => $v) {
    echo "  $k => $v\n";
}

// Identical - xor is empty
$e = new Judy(Judy::INT_TO_INT);
$f = new Judy(Judy::INT_TO_INT);
$e[1] = 10;
$e[2] = 20;
$f[1] = 99;
$f[2] = 88;

$result4 = $e->xor($f);
echo "Identical keys count: " . $result4->count() . "\n";

// Both empty
$g = new Judy(Judy::INT_TO_INT);
$h = new Judy(Judy::INT_TO_INT);
$result5 = $g->xor($h);
echo "Both empty count: " . $result5->count() . "\n";
?>
--EXPECT--
Xor count: 2
  1 => 10
  4 => 40
Reversed count: 2
  1 => 10
  4 => 40
Disjoint count: 4
  1 => 10
  2 => 20
  3 => 30
  4 => 40
Identical keys count: 0
Both empty count: 0
