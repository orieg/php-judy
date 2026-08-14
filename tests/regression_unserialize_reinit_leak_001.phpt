--TEST--
Regression: __unserialize on a populated object frees prior contents (no leak)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// __unserialize is public and may be called on a populated object. It must
// free the previous tree (and its zvals) before re-initialising, else the old
// contents leak. Each re-init below installs a large tree, so a missing free
// leaks it every iteration and memory grows without bound.
$data = [];
for ($i = 0; $i < 300; $i++) { $data[$i] = str_repeat('v', 64); }
$payload = ['type' => Judy::INT_TO_MIXED, 'data' => $data];

$j = new Judy(Judy::INT_TO_MIXED);
$j->__unserialize($payload);       // first install (baseline includes one tree)
$base = memory_get_usage();
for ($i = 0; $i < 50; $i++) {
    $j->__unserialize($payload);   // each must free the previous 300-entry tree
}
$growth = memory_get_usage() - $base;

echo "count after re-init: " . $j->count() . "\n";
echo "value: " . $j[1] . "\n";
// A missing free leaks ~50 * 300 zvals + string refs (megabytes). Post-fix
// growth is near zero; allow generous slack for allocator bookkeeping.
echo "bounded growth: " . ($growth < 100000 ? "yes" : "no ($growth)") . "\n";
?>
--EXPECT--
count after re-init: 300
value: vvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvv
bounded growth: yes
