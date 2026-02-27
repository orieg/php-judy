--TEST--
Check for Judy ITERATOR using foreach() and INT_TO_MIXED
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php 
$judy = new Judy(Judy::INT_TO_MIXED);

echo "Set 3 index\n";
$judy[1] = 100;
$judy[2] = "AAA";
$judy[3] = 103;

foreach($judy as $k=>$v)
    print "k: $k, v: $v\n";

unset($judy);

?>
--EXPECT--
Set 3 index
k: 1, v: 100
k: 2, v: AAA
k: 3, v: 103
