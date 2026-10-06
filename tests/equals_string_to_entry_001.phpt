--TEST--
Judy::equals() on STRING_TO_ENTRY compares entry values, not raw slots
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Pre-fix: the string-keyed trie branch compared JUDY_MVAL_READ() raw slots;
 * for STRING_TO_ENTRY the slot holds a judy_cache_entry_t*, so identical ENTRY
 * arrays returned bool(false) (control: STRING_TO_MIXED returns true), and the
 * fabricated "zval type byte" could decode as refcounted → latent bad deref.
 * The fix materialises both sides through judy_value_from_slot(), which yields
 * get()-consistent expiry semantics: an expired entry reads as NULL on both
 * sides (Gate-1 canonical ENTRY/expiry ruling; this test pins the ruling). */

function build(string $cls, array $kv): Judy {
    $j = new Judy($cls);
    foreach ($kv as $k => $v) {
        if ($cls === Judy::STRING_TO_ENTRY) { $j->set($k, $v); } else { $j[$k] = $v; }
    }
    return $j;
}

$pairs = ["k1" => "v1", "k2" => 42, "k3" => ["x" => 1]];

// identical contents → true (pre-fix: false on ENTRY, true on MIXED control)
$a = build(Judy::STRING_TO_ENTRY, $pairs);
$b = build(Judy::STRING_TO_ENTRY, $pairs);
var_dump($a->equals($b));
$a2 = build(Judy::STRING_TO_MIXED, $pairs);
$b2 = build(Judy::STRING_TO_MIXED, $pairs);
var_dump($a2->equals($b2));

// differing value → false
$c = build(Judy::STRING_TO_ENTRY, ["k1" => "v1", "k2" => 42, "k3" => ["x" => 2]]);
var_dump($a->equals($c));

// same count, different key set → false
$d = build(Judy::STRING_TO_ENTRY, ["k1" => "v1", "k2" => 42, "k4" => ["x" => 1]]);
var_dump($a->equals($d));

// differing value type (42 vs "42") → false
$e = build(Judy::STRING_TO_ENTRY, ["k1" => "v1", "k2" => "42", "k3" => ["x" => 1]]);
var_dump($a->equals($e));

// expired reads as NULL on both sides (get()-consistent): two expired entries
// with DIFFERENT stored values are equal, because get() returns NULL for both.
$x = new Judy(Judy::STRING_TO_ENTRY); $x->set("k", "old-a", ttl: -1);
$y = new Judy(Judy::STRING_TO_ENTRY); $y->set("k", "old-b", ttl: -1);
var_dump($x->equals($y));

// expired vs live → NULL vs value → false
$z = new Judy(Judy::STRING_TO_ENTRY); $z->set("k", "old-a", ttl: -1);
$w = new Judy(Judy::STRING_TO_ENTRY); $w->set("k", "live");
var_dump($z->equals($w));

// self and empty comparisons
var_dump($a->equals($a));
$e1 = new Judy(Judy::STRING_TO_ENTRY); $e2 = new Judy(Judy::STRING_TO_ENTRY);
var_dump($e1->equals($e2));

echo "Done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
Done
