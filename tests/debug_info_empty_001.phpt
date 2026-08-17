--TEST--
get_debug_info: empty and never-constructed Judy objects dump without boundary keys
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--INI--
judy.debug_preview_size=16
--FILE--
<?php
// Empty of every key category: bitset, integer-keyed, trie string-keyed,
// hash string-keyed and adaptive string-keyed.
foreach ([
    Judy::BITSET,
    Judy::INT_TO_INT,
    Judy::STRING_TO_MIXED,
    Judy::STRING_TO_INT_HASH,
    Judy::STRING_TO_MIXED_ADAPTIVE,
] as $type) {
    var_dump(new Judy($type));
}

// Emptied after use: the boundary keys must go back to null.
$j = new Judy(Judy::STRING_TO_MIXED);
$j["a"] = 1;
unset($j["a"]);
var_dump($j);

// Never constructed (no type argument): must not crash.
var_dump(new Judy());
?>
--EXPECTF--
object(Judy)#%d (6) {
  ["type"]=>
  string(6) "BITSET"
  ["count"]=>
  int(0)
  ["memoryUsage"]=>
  int(%d)
  ["firstKey"]=>
  NULL
  ["lastKey"]=>
  NULL
  ["preview"]=>
  array(0) {
  }
}
object(Judy)#%d (6) {
  ["type"]=>
  string(10) "INT_TO_INT"
  ["count"]=>
  int(0)
  ["memoryUsage"]=>
  int(%d)
  ["firstKey"]=>
  NULL
  ["lastKey"]=>
  NULL
  ["preview"]=>
  array(0) {
  }
}
object(Judy)#%d (6) {
  ["type"]=>
  string(15) "STRING_TO_MIXED"
  ["count"]=>
  int(0)
  ["memoryUsage"]=>
  NULL
  ["firstKey"]=>
  NULL
  ["lastKey"]=>
  NULL
  ["preview"]=>
  array(0) {
  }
}
object(Judy)#%d (6) {
  ["type"]=>
  string(18) "STRING_TO_INT_HASH"
  ["count"]=>
  int(0)
  ["memoryUsage"]=>
  NULL
  ["firstKey"]=>
  NULL
  ["lastKey"]=>
  NULL
  ["preview"]=>
  array(0) {
  }
}
object(Judy)#%d (6) {
  ["type"]=>
  string(24) "STRING_TO_MIXED_ADAPTIVE"
  ["count"]=>
  int(0)
  ["memoryUsage"]=>
  NULL
  ["firstKey"]=>
  NULL
  ["lastKey"]=>
  NULL
  ["preview"]=>
  array(0) {
  }
}
object(Judy)#%d (6) {
  ["type"]=>
  string(15) "STRING_TO_MIXED"
  ["count"]=>
  int(0)
  ["memoryUsage"]=>
  NULL
  ["firstKey"]=>
  NULL
  ["lastKey"]=>
  NULL
  ["preview"]=>
  array(0) {
  }
}
object(Judy)#%d (6) {
  ["type"]=>
  string(13) "UNINITIALIZED"
  ["count"]=>
  int(0)
  ["memoryUsage"]=>
  NULL
  ["firstKey"]=>
  NULL
  ["lastKey"]=>
  NULL
  ["preview"]=>
  array(0) {
  }
}
