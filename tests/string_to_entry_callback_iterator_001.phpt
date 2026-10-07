--TEST--
Judy STRING_TO_ENTRY: forEach/filter/map materialise entry values (no zval* confusion)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Pre-fix: judy_callback_iterator did ZVAL_COPY(&args[0], JUDY_MVAL_READ(VValue))
 * on the string-keyed branch, reading a judy_cache_entry_t* as a zval* → SIGSEGV
 * (exit 139) on every forEach/filter/map over STRING_TO_ENTRY. The fix routes
 * materialisation through judy_value_from_slot(), which has the ENTRY branch. */
$j = new Judy(Judy::STRING_TO_ENTRY);
$j->set("a", "va");
$j->set("b", "vb");
$j->set("c", "vc");
$j->set("gone", "vx", ttl: -1); // expires_at = now-1 → immediately expired

// forEach: callback order is ($value, $key) — see Step 5.1 for the docs fix.
$seen = [];
$j->forEach(function ($v, $k) use (&$seen) { $seen[$k] = $v; });
ksort($seen);
var_dump($seen);

// Expired entry: value-serving traversals hide it entirely (Gate-1 ruling b).
// The callback is NOT called for "gone" — foreach, keys(), filter() and map()
// agree; count() stays the raw counter (ruling c): 4 keys, 3 live. get() on
// the key still answers NULL, matching isset(). (Before Step 3.3 the callback
// WAS called with NULL; the ruling settled on hiding.)
$expiredValue = "callback-not-called";
$j->forEach(function ($v, $k) use (&$expiredValue) { if ($k === "gone") $expiredValue = $v; });
var_dump($expiredValue);

// filter on a 3-live-entry predicate
$f = $j->filter(fn($v) => $v === "vb");
var_dump($f->toArray());

// map uppercases strings; the expired entry never reaches the callback
$m = $j->map(fn($v) => is_string($v) ? strtoupper($v) : $v);
$mta = $m->toArray();
ksort($mta);
var_dump($mta);

echo "Done\n";
?>
--EXPECT--
array(3) {
  ["a"]=>
  string(2) "va"
  ["b"]=>
  string(2) "vb"
  ["c"]=>
  string(2) "vc"
}
string(19) "callback-not-called"
array(1) {
  ["b"]=>
  string(2) "vb"
}
array(3) {
  ["a"]=>
  string(2) "VA"
  ["b"]=>
  string(2) "VB"
  ["c"]=>
  string(2) "VC"
}
Done
