--TEST--
get_debug_info: var_dump() shows type/count/memoryUsage/boundary keys/preview for every Judy type
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--INI--
judy.debug_preview_size=16
--FILE--
<?php
$types = [
    Judy::BITSET,
    Judy::INT_TO_INT,
    Judy::INT_TO_PACKED,
    Judy::INT_TO_MIXED,
    Judy::STRING_TO_INT,
    Judy::STRING_TO_MIXED,
    Judy::STRING_TO_INT_HASH,
    Judy::STRING_TO_MIXED_HASH,
    Judy::STRING_TO_INT_ADAPTIVE,
    Judy::STRING_TO_MIXED_ADAPTIVE,
];

foreach ($types as $type) {
    $j = new Judy($type);
    switch ($type) {
        case Judy::BITSET:
            $j[3] = true;
            $j[7] = true;
            break;
        case Judy::INT_TO_INT:
            $j[3] = 30;
            $j[7] = 70;
            break;
        case Judy::INT_TO_PACKED:
        case Judy::INT_TO_MIXED:
            $j[3] = "three";
            $j[7] = "seven";
            break;
        case Judy::STRING_TO_INT:
        case Judy::STRING_TO_INT_HASH:
        case Judy::STRING_TO_INT_ADAPTIVE:
            $j["alpha"] = 1;
            $j["beta"] = 2;
            break;
        default:
            $j["alpha"] = "one";
            $j["beta"] = "two";
            break;
    }
    var_dump($j);
    unset($j);
}
?>
--EXPECTF--
object(Judy)#%d (6) {
  ["type"]=>
  string(6) "BITSET"
  ["count"]=>
  int(2)
  ["memoryUsage"]=>
  int(%d)
  ["firstKey"]=>
  int(3)
  ["lastKey"]=>
  int(7)
  ["preview"]=>
  array(2) {
    [0]=>
    int(3)
    [1]=>
    int(7)
  }
}
object(Judy)#%d (6) {
  ["type"]=>
  string(10) "INT_TO_INT"
  ["count"]=>
  int(2)
  ["memoryUsage"]=>
  int(%d)
  ["firstKey"]=>
  int(3)
  ["lastKey"]=>
  int(7)
  ["preview"]=>
  array(2) {
    [3]=>
    int(30)
    [7]=>
    int(70)
  }
}
object(Judy)#%d (6) {
  ["type"]=>
  string(13) "INT_TO_PACKED"
  ["count"]=>
  int(2)
  ["memoryUsage"]=>
  int(%d)
  ["firstKey"]=>
  int(3)
  ["lastKey"]=>
  int(7)
  ["preview"]=>
  array(2) {
    [3]=>
    string(5) "three"
    [7]=>
    string(5) "seven"
  }
}
object(Judy)#%d (6) {
  ["type"]=>
  string(12) "INT_TO_MIXED"
  ["count"]=>
  int(2)
  ["memoryUsage"]=>
  int(%d)
  ["firstKey"]=>
  int(3)
  ["lastKey"]=>
  int(7)
  ["preview"]=>
  array(2) {
    [3]=>
    string(5) "three"
    [7]=>
    string(5) "seven"
  }
}
object(Judy)#%d (7) {
  ["type"]=>
  string(13) "STRING_TO_INT"
  ["count"]=>
  int(2)
  ["memoryUsage"]=>
  int(%d)
  ["memoryUsageIsApproximate"]=>
  bool(true)
  ["firstKey"]=>
  string(5) "alpha"
  ["lastKey"]=>
  string(4) "beta"
  ["preview"]=>
  array(2) {
    ["alpha"]=>
    int(1)
    ["beta"]=>
    int(2)
  }
}
object(Judy)#%d (7) {
  ["type"]=>
  string(15) "STRING_TO_MIXED"
  ["count"]=>
  int(2)
  ["memoryUsage"]=>
  int(%d)
  ["memoryUsageIsApproximate"]=>
  bool(true)
  ["firstKey"]=>
  string(5) "alpha"
  ["lastKey"]=>
  string(4) "beta"
  ["preview"]=>
  array(2) {
    ["alpha"]=>
    string(3) "one"
    ["beta"]=>
    string(3) "two"
  }
}
object(Judy)#%d (7) {
  ["type"]=>
  string(18) "STRING_TO_INT_HASH"
  ["count"]=>
  int(2)
  ["memoryUsage"]=>
  int(%d)
  ["memoryUsageIsApproximate"]=>
  bool(true)
  ["firstKey"]=>
  string(5) "alpha"
  ["lastKey"]=>
  string(4) "beta"
  ["preview"]=>
  array(2) {
    ["alpha"]=>
    int(1)
    ["beta"]=>
    int(2)
  }
}
object(Judy)#%d (7) {
  ["type"]=>
  string(20) "STRING_TO_MIXED_HASH"
  ["count"]=>
  int(2)
  ["memoryUsage"]=>
  int(%d)
  ["memoryUsageIsApproximate"]=>
  bool(true)
  ["firstKey"]=>
  string(5) "alpha"
  ["lastKey"]=>
  string(4) "beta"
  ["preview"]=>
  array(2) {
    ["alpha"]=>
    string(3) "one"
    ["beta"]=>
    string(3) "two"
  }
}
object(Judy)#%d (7) {
  ["type"]=>
  string(22) "STRING_TO_INT_ADAPTIVE"
  ["count"]=>
  int(2)
  ["memoryUsage"]=>
  int(%d)
  ["memoryUsageIsApproximate"]=>
  bool(true)
  ["firstKey"]=>
  string(5) "alpha"
  ["lastKey"]=>
  string(4) "beta"
  ["preview"]=>
  array(2) {
    ["alpha"]=>
    int(1)
    ["beta"]=>
    int(2)
  }
}
object(Judy)#%d (7) {
  ["type"]=>
  string(24) "STRING_TO_MIXED_ADAPTIVE"
  ["count"]=>
  int(2)
  ["memoryUsage"]=>
  int(%d)
  ["memoryUsageIsApproximate"]=>
  bool(true)
  ["firstKey"]=>
  string(5) "alpha"
  ["lastKey"]=>
  string(4) "beta"
  ["preview"]=>
  array(2) {
    ["alpha"]=>
    string(3) "one"
    ["beta"]=>
    string(3) "two"
  }
}
