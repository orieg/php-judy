--TEST--
Judy mergeWith() - MIXED values are properly referenced, not aliased or leaked
--DESCRIPTION--
Issue #121: the merge loop now materialises a MIXED value straight out of the
iterator's slot, which is a raw zval* — it has to take a reference (ZVAL_COPY
semantics), or the destination ends up holding a dangling pointer once the
source is freed, or holding one reference too many and never releasing it. This
asserts both directions: the destination survives the source being dropped, and
every tracked object is destroyed exactly once when both arrays go away.
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
class Tracked {
    public static int $live = 0;
    public function __construct(public string $tag) { self::$live++; }
    public function __destruct() { self::$live--; }
}

$types = [
    'INT_TO_MIXED'             => Judy::INT_TO_MIXED,
    'STRING_TO_MIXED'          => Judy::STRING_TO_MIXED,
    'STRING_TO_MIXED_HASH'     => Judy::STRING_TO_MIXED_HASH,
    'STRING_TO_MIXED_ADAPTIVE' => Judy::STRING_TO_MIXED_ADAPTIVE,
];

foreach ($types as $name => $type) {
    echo "=== $name ===\n";
    $int = ($type === Judy::INT_TO_MIXED);
    $k1 = $int ? 1 : 'k1';                 // short key: below the ADAPTIVE SSO boundary
    $k2 = $int ? 2 : 'a_long_key_two';     // long key: above it

    $src = new Judy($type);
    $dst = new Judy($type);

    $obj = new Tracked('obj');
    $arr = ['deep' => ['nested' => 'value'], 'n' => 7];
    $src[$k1] = $obj;
    $src[$k2] = $arr;
    $dst[$k1] = 'to be overwritten';

    $dst->mergeWith($src);

    // Drop every reference the source side held.
    unset($src, $obj, $arr);

    echo "live objects after dropping source: " . Tracked::$live . "\n";
    echo "k1 class=" . get_class($dst[$k1]) . " tag=" . $dst[$k1]->tag . "\n";
    echo "k2 deep=" . $dst[$k2]['deep']['nested'] . " n=" . $dst[$k2]['n'] . "\n";

    // A read hands back a copy; mutating it must not reach into the store.
    $tmp = $dst[$k2];
    $tmp['n'] = 99;
    echo "stored n after mutating the read copy: " . $dst[$k2]['n'] . "\n";

    unset($dst, $tmp);
    echo "live objects after dropping destination: " . Tracked::$live . "\n";
}

echo "=== repeated merges keep the object count exact ===\n";
for ($round = 0; $round < 3; $round++) {
    $src = new Judy(Judy::INT_TO_MIXED);
    for ($i = 0; $i < 200; $i++) { $src[$i] = new Tracked("r$round-$i"); }
    $dst = new Judy(Judy::INT_TO_MIXED);
    $dst->mergeWith($src);
    $dst->mergeWith($src);  // second pass overwrites every key with itself
    unset($src);
    echo "round $round live after source freed: " . Tracked::$live . "\n";
    unset($dst);
    echo "round $round live after destination freed: " . Tracked::$live . "\n";
}

echo "=== PHP heap does not grow across merge rounds ===\n";
$baseline = null;
$grown = false;
for ($round = 0; $round < 12; $round++) {
    $src = new Judy(Judy::STRING_TO_MIXED_HASH);
    for ($i = 0; $i < 500; $i++) { $src["key_number_$i"] = ['payload' => str_repeat('x', 32), 'i' => $i]; }
    $dst = new Judy(Judy::STRING_TO_MIXED_HASH);
    $dst->mergeWith($src);
    $dst->mergeWith($src);
    unset($src, $dst);
    gc_collect_cycles();
    if ($round === 3) { $baseline = memory_get_usage(); }
    if ($baseline !== null && memory_get_usage() > $baseline + 65536) { $grown = true; }
}
echo "heap grew past the settled baseline: " . var_export($grown, true) . "\n";
echo "live objects at end: " . Tracked::$live . "\n";
echo "Done.\n";
?>
--EXPECT--
=== INT_TO_MIXED ===
live objects after dropping source: 1
k1 class=Tracked tag=obj
k2 deep=value n=7
stored n after mutating the read copy: 7
live objects after dropping destination: 0
=== STRING_TO_MIXED ===
live objects after dropping source: 1
k1 class=Tracked tag=obj
k2 deep=value n=7
stored n after mutating the read copy: 7
live objects after dropping destination: 0
=== STRING_TO_MIXED_HASH ===
live objects after dropping source: 1
k1 class=Tracked tag=obj
k2 deep=value n=7
stored n after mutating the read copy: 7
live objects after dropping destination: 0
=== STRING_TO_MIXED_ADAPTIVE ===
live objects after dropping source: 1
k1 class=Tracked tag=obj
k2 deep=value n=7
stored n after mutating the read copy: 7
live objects after dropping destination: 0
=== repeated merges keep the object count exact ===
round 0 live after source freed: 200
round 0 live after destination freed: 0
round 1 live after source freed: 200
round 1 live after destination freed: 0
round 2 live after source freed: 200
round 2 live after destination freed: 0
=== PHP heap does not grow across merge rounds ===
heap grew past the settled baseline: false
live objects at end: 0
Done.
