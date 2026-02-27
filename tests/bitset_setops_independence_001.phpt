--TEST--
Judy BITSET set operations - original arrays remain unmodified
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$a = new Judy(Judy::BITSET);
$b = new Judy(Judy::BITSET);
$a[1] = true;
$a[2] = true;
$a[3] = true;
$b[3] = true;
$b[4] = true;
$b[5] = true;

// Take snapshots
$a_count_before = $a->count();
$b_count_before = $b->count();

// Perform all operations
$union = $a->union($b);
$intersect = $a->intersect($b);
$diff = $a->diff($b);
$xor = $a->xor($b);

// Verify originals unmodified
echo "a count before: $a_count_before, after: " . $a->count() . "\n";
echo "b count before: $b_count_before, after: " . $b->count() . "\n";

// Verify a still has exactly {1, 2, 3}
echo "a contents:";
foreach ($a as $k => $v) echo " $k";
echo "\n";

// Verify b still has exactly {3, 4, 5}
echo "b contents:";
foreach ($b as $k => $v) echo " $k";
echo "\n";

// Verify results are independent objects
echo "union type: " . $union->getType() . "\n";
echo "intersect type: " . $intersect->getType() . "\n";
echo "diff type: " . $diff->getType() . "\n";
echo "xor type: " . $xor->getType() . "\n";

// Modify result and verify original unchanged
$union[100] = true;
echo "union count after modify: " . $union->count() . "\n";
echo "a count after union modify: " . $a->count() . "\n";
echo "b count after union modify: " . $b->count() . "\n";
?>
--EXPECT--
a count before: 3, after: 3
b count before: 3, after: 3
a contents: 1 2 3
b contents: 3 4 5
union type: 1
intersect type: 1
diff type: 1
xor type: 1
union count after modify: 6
a count after union modify: 3
b count after union modify: 3
