--TEST--
Judy deleteRange() for integer-keyed and string-keyed types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// BITSET
$j = new Judy(Judy::BITSET);
for ($i = 0; $i < 20; $i++) {
    $j[$i] = true;
}
echo "BITSET before: " . count($j) . "\n";
$deleted = $j->deleteRange(5, 14);
echo "BITSET deleted: $deleted\n";
echo "BITSET after: " . count($j) . "\n";
echo "BITSET remaining keys: ";
var_dump($j->keys());

// INT_TO_INT
$j2 = new Judy(Judy::INT_TO_INT);
$j2[10] = 100;
$j2[20] = 200;
$j2[30] = 300;
$j2[40] = 400;
$j2[50] = 500;
echo "\nINT_TO_INT before: " . count($j2) . "\n";
$deleted = $j2->deleteRange(20, 40);
echo "INT_TO_INT deleted: $deleted\n";
echo "INT_TO_INT after: " . count($j2) . "\n";
echo "INT_TO_INT remaining: ";
var_dump($j2->toArray());

// INT_TO_MIXED — verify value cleanup
$j3 = new Judy(Judy::INT_TO_MIXED);
$j3[1] = "hello";
$j3[2] = [1, 2, 3];
$j3[3] = "world";
$j3[4] = 42;
echo "\nINT_TO_MIXED before: " . count($j3) . "\n";
$deleted = $j3->deleteRange(2, 3);
echo "INT_TO_MIXED deleted: $deleted\n";
echo "INT_TO_MIXED after: " . count($j3) . "\n";

// STRING_TO_INT
$j4 = new Judy(Judy::STRING_TO_INT);
$j4["apple"] = 1;
$j4["banana"] = 2;
$j4["cherry"] = 3;
$j4["date"] = 4;
echo "\nSTRING_TO_INT before: " . count($j4) . "\n";
$deleted = $j4->deleteRange("banana", "cherry");
echo "STRING_TO_INT deleted: $deleted\n";
echo "STRING_TO_INT after: " . count($j4) . "\n";
echo "STRING_TO_INT remaining keys: ";
var_dump($j4->keys());

// Empty range (no elements in range)
$j5 = new Judy(Judy::INT_TO_INT);
$j5[100] = 1;
$deleted = $j5->deleteRange(0, 50);
echo "\nEmpty range deleted: $deleted\n";
echo "Count after empty range: " . count($j5) . "\n";

// Delete all
$j6 = new Judy(Judy::INT_TO_INT);
$j6[1] = 10;
$j6[2] = 20;
$j6[3] = 30;
$deleted = $j6->deleteRange(0, -1);
echo "\nDelete all: $deleted\n";
echo "Count after delete all: " . count($j6) . "\n";
?>
--EXPECT--
BITSET before: 20
BITSET deleted: 10
BITSET after: 10
BITSET remaining keys: array(10) {
  [0]=>
  int(0)
  [1]=>
  int(1)
  [2]=>
  int(2)
  [3]=>
  int(3)
  [4]=>
  int(4)
  [5]=>
  int(15)
  [6]=>
  int(16)
  [7]=>
  int(17)
  [8]=>
  int(18)
  [9]=>
  int(19)
}

INT_TO_INT before: 5
INT_TO_INT deleted: 3
INT_TO_INT after: 2
INT_TO_INT remaining: array(2) {
  [10]=>
  int(100)
  [50]=>
  int(500)
}

INT_TO_MIXED before: 4
INT_TO_MIXED deleted: 2
INT_TO_MIXED after: 2

STRING_TO_INT before: 4
STRING_TO_INT deleted: 2
STRING_TO_INT after: 2
STRING_TO_INT remaining keys: array(2) {
  [0]=>
  string(5) "apple"
  [1]=>
  string(4) "date"
}

Empty range deleted: 0
Count after empty range: 1

Delete all: 3
Count after delete all: 0

