--TEST--
Check for Judy presence
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
echo "judy extension is available\n";

// Version reported three ways must agree and be a valid semver, without
// hard-coding the number here (so a release only touches php_judy.h and
// package.xml, not this test).
$version = judy_version();
var_dump($version === JUDY_VERSION);           // judy_version() == constant
var_dump(phpversion('judy') === JUDY_VERSION); // alias == constant
var_dump((bool) preg_match('/^\d+\.\d+\.\d+/', JUDY_VERSION)); // semver shape
?>
--EXPECT--
judy extension is available
bool(true)
bool(true)
bool(true)
