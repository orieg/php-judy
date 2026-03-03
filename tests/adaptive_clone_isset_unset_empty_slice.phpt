--TEST--
Judy Adaptive Types: clone, isset, unset, empty, slice, append error
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php

echo "=== clone with short and long keys ===\n";
$j = new Judy(Judy::STRING_TO_MIXED_ADAPTIVE);
$j["abc"] = 100;
$j["defg"] = 200;
$j["long_key_12345"] = "hello";
$j["another_long_key"] = ["arr"];

$c = clone $j;
echo "orig size: " . $j->size() . "\n";
echo "clone size: " . $c->size() . "\n";
echo "clone abc: " . $c["abc"] . "\n";
echo "clone defg: " . $c["defg"] . "\n";
echo "clone long_key_12345: " . $c["long_key_12345"] . "\n";
echo "clone another_long_key: " . (is_array($c["another_long_key"]) ? "array" : "not array") . "\n";

// Modify clone doesn't affect original
$c["abc"] = 999;
echo "orig abc after clone mod: " . $j["abc"] . "\n";

echo "\n=== clone INT_ADAPTIVE ===\n";
$ji = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
$ji["ab"] = 10;
$ji["long_key_test123"] = 20;
$ci = clone $ji;
echo "clone ab: " . $ci["ab"] . "\n";
echo "clone long_key_test123: " . $ci["long_key_test123"] . "\n";

echo "\n=== isset with only long keys ===\n";
$j2 = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
$j2["long_key_12345"] = 42;
echo "isset(long_key_12345): " . (isset($j2["long_key_12345"]) ? "true" : "false") . "\n";
echo "isset(nonexist): " . (isset($j2["nonexist"]) ? "true" : "false") . "\n";

echo "\n=== isset with only short keys ===\n";
$j3 = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
$j3["abc"] = 10;
echo "isset(abc): " . (isset($j3["abc"]) ? "true" : "false") . "\n";

echo "\n=== unset with only long keys ===\n";
$j4 = new Judy(Judy::STRING_TO_MIXED_ADAPTIVE);
$j4["a_very_long_key"] = "val";
echo "before unset size: " . $j4->size() . "\n";
unset($j4["a_very_long_key"]);
echo "after unset size: " . $j4->size() . "\n";
echo "isset after unset: " . (isset($j4["a_very_long_key"]) ? "true" : "false") . "\n";

echo "\n=== empty() on adaptive values ===\n";
$j5 = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
$j5["abc"] = 0;
$j5["def"] = 42;
$j5["long_key_notempty"] = 100;
$j5["long_key_empty"] = 0;
echo "empty(abc=0): " . (empty($j5["abc"]) ? "true" : "false") . "\n";
echo "empty(def=42): " . (empty($j5["def"]) ? "true" : "false") . "\n";
echo "empty(long_key_notempty=100): " . (empty($j5["long_key_notempty"]) ? "true" : "false") . "\n";
echo "empty(long_key_empty=0): " . (empty($j5["long_key_empty"]) ? "true" : "false") . "\n";

$j6 = new Judy(Judy::STRING_TO_MIXED_ADAPTIVE);
$j6["abc"] = "";
$j6["def"] = "notempty";
echo "empty(abc=''): " . (empty($j6["abc"]) ? "true" : "false") . "\n";
echo "empty(def='notempty'): " . (empty($j6["def"]) ? "true" : "false") . "\n";

echo "\n=== append error ===\n";
$j7 = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
try {
    $j7[] = 42;
    echo "ERROR: should have thrown\n";
} catch (Exception $e) {
    echo "caught: " . (strpos($e->getMessage(), "cannot be set without specifying a key") !== false ? "ok" : $e->getMessage()) . "\n";
}

$j8 = new Judy(Judy::STRING_TO_MIXED_ADAPTIVE);
try {
    $j8[] = "val";
    echo "ERROR: should have thrown\n";
} catch (Exception $e) {
    echo "caught: " . (strpos($e->getMessage(), "cannot be set without specifying a key") !== false ? "ok" : $e->getMessage()) . "\n";
}

echo "\n=== slice on adaptive ===\n";
$js = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
$js["aa"] = 1;
$js["ab"] = 2;
$js["ba"] = 3;
$js["bb"] = 4;
$js["long_key_cc"] = 5;
$js["long_key_dd"] = 6;

$sl = $js->slice("ab", "long_key_cc");
echo "slice size: " . $sl->size() . "\n";
echo "slice iteration:\n";
foreach ($sl as $k => $v) {
    echo "  $k => $v\n";
}

$jm = new Judy(Judy::STRING_TO_MIXED_ADAPTIVE);
$jm["aa"] = "first";
$jm["bb"] = "second";
$jm["long_key_cc"] = "third";
$slm = $jm->slice("bb", "long_key_cc");
echo "mixed slice size: " . $slm->size() . "\n";
echo "slice iteration:\n";
foreach ($slm as $k => $v) {
    echo "  $k => $v\n";
}

echo "\nDone.\n";
?>
--EXPECT--
=== clone with short and long keys ===
orig size: 4
clone size: 4
clone abc: 100
clone defg: 200
clone long_key_12345: hello
clone another_long_key: array
orig abc after clone mod: 100

=== clone INT_ADAPTIVE ===
clone ab: 10
clone long_key_test123: 20

=== isset with only long keys ===
isset(long_key_12345): true
isset(nonexist): false

=== isset with only short keys ===
isset(abc): true

=== unset with only long keys ===
before unset size: 1
after unset size: 0
isset after unset: false

=== empty() on adaptive values ===
empty(abc=0): true
empty(def=42): false
empty(long_key_notempty=100): false
empty(long_key_empty=0): true
empty(abc=''): true
empty(def='notempty'): false

=== append error ===
caught: ok
caught: ok

=== slice on adaptive ===
slice size: 4
slice iteration:
  ab => 2
  ba => 3
  bb => 4
  long_key_cc => 5
mixed slice size: 2
slice iteration:
  bb => second
  long_key_cc => third

Done.
