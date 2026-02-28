--TEST--
Judy boundary: long string keys for STRING_TO_INT
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_INT);

// 1000-char key
$long_key = str_repeat("a", 1000);
$j[$long_key] = 42;
echo "1000-char key stored: " . ($j[$long_key] === 42 ? "yes" : "no") . "\n";
echo "1000-char key exists: " . (isset($j[$long_key]) ? "yes" : "no") . "\n";

// Different long keys
$key_a = str_repeat("a", 500);
$key_b = str_repeat("b", 500);
$j[$key_a] = 1;
$j[$key_b] = 2;
echo "500-char key 'a': " . $j[$key_a] . "\n";
echo "500-char key 'b': " . $j[$key_b] . "\n";

// Single char key alongside long keys
$j["x"] = 99;
echo "Single char key: " . $j["x"] . "\n";

echo "Count: " . $j->count() . "\n";
?>
--EXPECT--
1000-char key stored: yes
1000-char key exists: yes
500-char key 'a': 1
500-char key 'b': 2
Single char key: 99
Count: 4
