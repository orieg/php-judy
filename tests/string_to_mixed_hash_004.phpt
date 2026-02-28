--TEST--
Check for Judy STRING_TO_MIXED_HASH foreach iteration returns keys in alphabetical order
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_MIXED_HASH);

// Insert in non-alphabetical order
$judy["zebra"]  = "z";
$judy["apple"]  = "a";
$judy["mango"]  = "m";
$judy["banana"] = "b";
$judy["cherry"] = "c";

// foreach should yield keys in alphabetical order (via JudySL key_index)
echo "foreach:\n";
foreach ($judy as $k => $v) {
    echo "  $k => $v\n";
}

echo "Done\n";
?>
--EXPECT--
foreach:
  apple => a
  banana => b
  cherry => c
  mango => m
  zebra => z
Done
