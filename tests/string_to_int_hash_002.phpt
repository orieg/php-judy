--TEST--
Check for Judy STRING_TO_INT_HASH values are stored as integers
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_INT_HASH);

// String values are coerced to int
$judy["str"] = "hello";
var_dump($judy["str"]);

// Integer values stored directly
$judy["num"] = 42;
var_dump($judy["num"]);

// Float values truncated to int
$judy["pi"] = 3.14;
var_dump($judy["pi"]);

// Bool values coerced
$judy["flag"] = true;
var_dump($judy["flag"]);

// Negative values
$judy["neg"] = -99;
var_dump($judy["neg"]);

// Zero
$judy["zero"] = 0;
var_dump($judy["zero"]);

// Overwrite existing key
$judy["num"] = 100;
var_dump($judy["num"]);

echo "Done\n";
?>
--EXPECT--
int(0)
int(42)
int(3)
int(1)
int(-99)
int(0)
int(100)
Done
