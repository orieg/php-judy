--TEST--
Judy INT_TO_PACKED external iterator (get_iterator path)
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);
$j[0] = "alpha";
$j[3] = "beta";
$j[7] = "gamma";

$it = new IteratorIterator($j);
$it->rewind();
while ($it->valid()) {
    echo $it->key() . " => " . $it->current() . "\n";
    $it->next();
}

echo "Done\n";
?>
--EXPECT--
0 => alpha
3 => beta
7 => gamma
Done
