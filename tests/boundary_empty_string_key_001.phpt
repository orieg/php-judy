--TEST--
Judy boundary: empty string as key
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// STRING_TO_INT with empty key
$j = new Judy(Judy::STRING_TO_INT);
$j[""] = 42;
echo "Empty key stored: " . ($j[""] === 42 ? "yes" : "no") . "\n";
echo "Empty key exists: " . (isset($j[""]) ? "yes" : "no") . "\n";

// Non-empty key alongside
$j["hello"] = 100;
echo "Count: " . $j->count() . "\n";
echo "Non-empty key: " . $j["hello"] . "\n";

// Unset empty key
unset($j[""]);
echo "After unset empty key, count: " . $j->count() . "\n";
echo "Empty key exists: " . (isset($j[""]) ? "yes" : "no") . "\n";
echo "Non-empty key still: " . $j["hello"] . "\n";
?>
--EXPECT--
Empty key stored: yes
Empty key exists: yes
Count: 2
Non-empty key: 100
After unset empty key, count: 1
Empty key exists: no
Non-empty key still: 100
