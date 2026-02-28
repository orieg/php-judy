--TEST--
Judy INT_TO_PACKED foreach iteration (PHP_METHOD iterator path)
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);

$j[1] = "one";
$j[5] = 42;
$j[10] = [1, 2];

foreach ($j as $k => $v) {
    echo "k: $k, v: " . var_export($v, true) . "\n";
}

echo "Done\n";
?>
--EXPECT--
k: 1, v: 'one'
k: 5, v: 42
k: 10, v: array (
  0 => 1,
  1 => 2,
)
Done
