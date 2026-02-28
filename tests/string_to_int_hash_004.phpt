--TEST--
Check for Judy STRING_TO_INT_HASH foreach iteration returns keys in alphabetical order
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_INT_HASH);

// Insert in non-alphabetical order
$judy["zebra"]  = 26;
$judy["apple"]  = 1;
$judy["mango"]  = 13;
$judy["banana"] = 2;
$judy["cherry"] = 3;

// foreach should yield keys in alphabetical order (via JudySL key_index)
echo "foreach:\n";
foreach ($judy as $k => $v) {
    echo "  $k => $v\n";
}

echo "Done\n";
?>
--EXPECT--
foreach:
  apple => 1
  banana => 2
  cherry => 3
  mango => 13
  zebra => 26
Done
