--TEST--
Judy INT_TO_INT diff() - overlapping, disjoint, and empty
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Overlapping - keep entries from self not in other
$a = new Judy(Judy::INT_TO_INT);
$b = new Judy(Judy::INT_TO_INT);
$a[1] = 10;
$a[2] = 20;
$a[3] = 30;
$a[4] = 40;
$b[2] = 999;
$b[4] = 888;
$b[5] = 50;

$result = $a->diff($b);
echo "Diff a-b count: " . $result->count() . "\n";
foreach ($result as $k => $v) {
    echo "  $k => $v\n";
}

// Reversed
$result2 = $b->diff($a);
echo "Diff b-a count: " . $result2->count() . "\n";
foreach ($result2 as $k => $v) {
    echo "  $k => $v\n";
}

// Disjoint - diff is same as self
$c = new Judy(Judy::INT_TO_INT);
$d = new Judy(Judy::INT_TO_INT);
$c[1] = 10;
$c[3] = 30;
$d[2] = 20;
$d[4] = 40;

$result3 = $c->diff($d);
echo "Disjoint count: " . $result3->count() . "\n";
foreach ($result3 as $k => $v) {
    echo "  $k => $v\n";
}

// Empty other - diff is same as self
$e = new Judy(Judy::INT_TO_INT);
$f = new Judy(Judy::INT_TO_INT);
$e[1] = 10;
$e[2] = 20;

$result4 = $e->diff($f);
echo "Empty other count: " . $result4->count() . "\n";
foreach ($result4 as $k => $v) {
    echo "  $k => $v\n";
}

// Empty self - diff is empty
$result5 = $f->diff($e);
echo "Empty self count: " . $result5->count() . "\n";
?>
--EXPECT--
Diff a-b count: 2
  1 => 10
  3 => 30
Diff b-a count: 1
  5 => 50
Disjoint count: 2
  1 => 10
  3 => 30
Empty other count: 2
  1 => 10
  2 => 20
Empty self count: 0
