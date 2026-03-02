--TEST--
Judy Phase 3: Advanced Iteration (forEach, filter, map)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
echo "--- INT_TO_INT ---
";
$j = new Judy(Judy::INT_TO_INT);
$j[1] = 10;
$j[2] = 20;
$j[3] = 30;

echo "forEach:
";
$j->forEach(function($v, $k) {
    echo "  $k => $v
";
});

echo "filter (even values):
";
$f = $j->filter(function($v) { return $v % 20 == 0; });
foreach($f as $k => $v) echo "  $k => $v
";

echo "map (v * 2):
";
$m = $j->map(function($v) { return $v * 2; });
foreach($m as $k => $v) echo "  $k => $v
";

echo "
--- STRING_TO_INT_HASH ---
";
$j = new Judy(Judy::STRING_TO_INT_HASH);
$j["a"] = 1;
$j["b"] = 2;
$j["c"] = 3;

echo "filter (values > 1):
";
$f = $j->filter(function($v) { return $v > 1; });
foreach($f as $k => $v) echo "  $k => $v
";

echo "map (v + 10):
";
$m = $j->map(function($v) { return $v + 10; });
foreach($m as $k => $v) echo "  $k => $v
";

echo "
--- BITSET ---
";
$j = new Judy(Judy::BITSET);
$j[10] = true;
$j[20] = true;
$j[30] = true;

echo "forEach:
";
$j->forEach(function($v, $k) {
    echo "  $k => " . ($v ? "true" : "false") . "
";
});

?>
--EXPECT--
--- INT_TO_INT ---
forEach:
  1 => 10
  2 => 20
  3 => 30
filter (even values):
  2 => 20
map (v * 2):
  1 => 20
  2 => 40
  3 => 60

--- STRING_TO_INT_HASH ---
filter (values > 1):
  b => 2
  c => 3
map (v + 10):
  a => 11
  b => 12
  c => 13

--- BITSET ---
forEach:
  10 => true
  20 => true
  30 => true
