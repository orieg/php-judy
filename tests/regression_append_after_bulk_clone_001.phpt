--TEST--
Regression: append ($j[] =) after fromArray/putAll/clone must not overwrite index 0
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// The append watermark (next_empty) must be invalidated by bulk populate and
// clone, otherwise the first $j[] = lands at index 0 and clobbers an element.

// fromArray
$a = Judy::fromArray(Judy::INT_TO_INT, [0 => 100, 1 => 200, 2 => 300]);
$a[] = 999;
echo "fromArray: index 0 = {$a[0]}, appended = {$a[3]}, count = " . $a->count() . "\n";

// putAll
$b = new Judy(Judy::INT_TO_INT);
$b->putAll([0 => 10, 1 => 20]);
$b[] = 30;
echo "putAll: index 0 = {$b[0]}, appended = {$b[2]}, count = " . $b->count() . "\n";

// clone
$c = new Judy(Judy::INT_TO_INT);
$c[0] = 1; $c[1] = 2;
$d = clone $c;
$d[] = 3;
echo "clone: index 0 = {$d[0]}, appended = {$d[2]}, count = " . $d->count() . "\n";
?>
--EXPECT--
fromArray: index 0 = 100, appended = 999, count = 4
putAll: index 0 = 10, appended = 30, count = 3
clone: index 0 = 1, appended = 3, count = 3
