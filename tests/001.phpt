--TEST--
Check for Judy presence
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
echo "judy extension is available\n";
$version = judy_version();
var_dump($version);

// judy_version() is an alias of phpversion('judy')
var_dump(phpversion('judy'));

// Test constant
var_dump(JUDY_VERSION);
?>
--EXPECTF--
judy extension is available
string(5) "2.3.0"
string(5) "2.3.0"
string(5) "2.3.0"
