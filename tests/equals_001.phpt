--TEST--
Judy equals() for all types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// INT_TO_INT — equal
$a = new Judy(Judy::INT_TO_INT);
$b = new Judy(Judy::INT_TO_INT);
$a[1] = 10; $a[2] = 20;
$b[1] = 10; $b[2] = 20;
echo "INT_TO_INT equal: " . var_export($a->equals($b), true) . "\n";

// INT_TO_INT — different values
$c = new Judy(Judy::INT_TO_INT);
$c[1] = 10; $c[2] = 99;
echo "INT_TO_INT diff values: " . var_export($a->equals($c), true) . "\n";

// INT_TO_INT — different keys
$d = new Judy(Judy::INT_TO_INT);
$d[1] = 10; $d[3] = 20;
echo "INT_TO_INT diff keys: " . var_export($a->equals($d), true) . "\n";

// Different counts
$e = new Judy(Judy::INT_TO_INT);
$e[1] = 10;
echo "INT_TO_INT diff count: " . var_export($a->equals($e), true) . "\n";

// Identity
echo "Identity: " . var_export($a->equals($a), true) . "\n";

// Empty arrays
$f = new Judy(Judy::INT_TO_INT);
$g = new Judy(Judy::INT_TO_INT);
echo "Both empty: " . var_export($f->equals($g), true) . "\n";

// BITSET
$h = new Judy(Judy::BITSET);
$i = new Judy(Judy::BITSET);
$h[5] = true; $h[10] = true;
$i[5] = true; $i[10] = true;
echo "BITSET equal: " . var_export($h->equals($i), true) . "\n";
$i[15] = true;
echo "BITSET diff: " . var_export($h->equals($i), true) . "\n";

// STRING_TO_INT
$s1 = new Judy(Judy::STRING_TO_INT);
$s2 = new Judy(Judy::STRING_TO_INT);
$s1["foo"] = 1; $s1["bar"] = 2;
$s2["foo"] = 1; $s2["bar"] = 2;
echo "STRING_TO_INT equal: " . var_export($s1->equals($s2), true) . "\n";
$s2["bar"] = 99;
echo "STRING_TO_INT diff: " . var_export($s1->equals($s2), true) . "\n";

// Type mismatch
$t1 = new Judy(Judy::INT_TO_INT);
$t2 = new Judy(Judy::INT_TO_MIXED);
echo "Type mismatch: " . var_export($t1->equals($t2), true) . "\n";

// INT_TO_MIXED with complex values
$m1 = new Judy(Judy::INT_TO_MIXED);
$m2 = new Judy(Judy::INT_TO_MIXED);
$m1[0] = "hello"; $m1[1] = 42; $m1[2] = true;
$m2[0] = "hello"; $m2[1] = 42; $m2[2] = true;
echo "INT_TO_MIXED equal: " . var_export($m1->equals($m2), true) . "\n";
$m2[2] = false;
echo "INT_TO_MIXED diff: " . var_export($m1->equals($m2), true) . "\n";
?>
--EXPECT--
INT_TO_INT equal: true
INT_TO_INT diff values: false
INT_TO_INT diff keys: false
INT_TO_INT diff count: false
Identity: true
Both empty: true
BITSET equal: true
BITSET diff: false
STRING_TO_INT equal: true
STRING_TO_INT diff: false
Type mismatch: false
INT_TO_MIXED equal: true
INT_TO_MIXED diff: false

