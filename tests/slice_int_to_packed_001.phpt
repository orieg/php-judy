--TEST--
Judy INT_TO_PACKED slice returns correct subset
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);
$j[0] = "zero";
$j[5] = "five";
$j[10] = "ten";
$j[15] = "fifteen";
$j[20] = "twenty";

$slice = $j->slice(5, 15);

echo "Type: " . $slice->getType() . "\n";
echo "Count: " . $slice->count() . "\n";

foreach ($slice as $k => $v) {
    echo "  $k => $v\n";
}
echo "Done\n";
?>
--EXPECT--
Type: 6
Count: 3
  5 => five
  10 => ten
  15 => fifteen
Done
