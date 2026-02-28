--TEST--
Judy boundary: keys with spaces, unicode, and special characters
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_INT);

// Key with spaces
$j["hello world"] = 1;
echo "Space key: " . $j["hello world"] . "\n";

// Key with special chars
$j["key!@#\$%"] = 2;
echo "Special chars: " . $j["key!@#\$%"] . "\n";

// Key with newline
$j["line1\nline2"] = 3;
echo "Newline key: " . $j["line1\nline2"] . "\n";

// Key with tab
$j["col1\tcol2"] = 4;
echo "Tab key: " . $j["col1\tcol2"] . "\n";

// UTF-8 key
$j["café"] = 5;
echo "UTF-8 key: " . $j["café"] . "\n";

// Key with unicode emoji
$j["test🎉"] = 6;
echo "Emoji key: " . $j["test🎉"] . "\n";

echo "Count: " . $j->count() . "\n";

// Verify all keys are distinct
var_dump($j->toArray());
?>
--EXPECT--
Space key: 1
Special chars: 2
Newline key: 3
Tab key: 4
UTF-8 key: 5
Emoji key: 6
Count: 6
array(6) {
  ["café"]=>
  int(5)
  ["col1	col2"]=>
  int(4)
  ["hello world"]=>
  int(1)
  ["key!@#$%"]=>
  int(2)
  ["line1
line2"]=>
  int(3)
  ["test🎉"]=>
  int(6)
}
