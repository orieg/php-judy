--TEST--
Judy::filter() copies the value the predicate saw (snapshot), not a re-read
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// filter() used to re-read $this[$key] after the predicate returned, which was
// a second full lookup per surviving element and made the copied value depend
// on whatever the predicate did to $this. It now copies the value the iterator
// already handed the predicate. This test pins that contract.

$types = [
    'INT_TO_INT'                => Judy::INT_TO_INT,
    'INT_TO_MIXED'              => Judy::INT_TO_MIXED,
    'INT_TO_PACKED'             => Judy::INT_TO_PACKED,
    'STRING_TO_INT'             => Judy::STRING_TO_INT,
    'STRING_TO_MIXED'           => Judy::STRING_TO_MIXED,
    'STRING_TO_INT_HASH'        => Judy::STRING_TO_INT_HASH,
    'STRING_TO_MIXED_HASH'      => Judy::STRING_TO_MIXED_HASH,
    'STRING_TO_INT_ADAPTIVE'    => Judy::STRING_TO_INT_ADAPTIVE,
    'STRING_TO_MIXED_ADAPTIVE'  => Judy::STRING_TO_MIXED_ADAPTIVE,
];

function fill(Judy $j, bool $stringKeyed): array
{
    $keys = $stringKeyed
        ? ['a', 'bb', 'ccc', str_repeat('z', 40)]
        : [0, 1, 2, 3];
    foreach ($keys as $i => $k) {
        $j[$k] = ($i + 1) * 10;
    }
    return $keys;
}

echo "== plain predicate ==\n";
foreach ($types as $name => $t) {
    $j = new Judy($t);
    $stringKeyed = str_starts_with($name, 'STRING_');
    fill($j, $stringKeyed);
    $out = $j->filter(fn($v, $k) => $v >= 20);
    echo "$name: " . json_encode($out->toArray()) . "\n";
}

echo "\n== predicate mutates \$this[\$k] in place ==\n";
foreach ($types as $name => $t) {
    $j = new Judy($t);
    $stringKeyed = str_starts_with($name, 'STRING_');
    fill($j, $stringKeyed);
    // Snapshot semantics: filter copies 10/20/30/40, not the 999 written back.
    $out = $j->filter(function ($v, $k) use ($j) { $j[$k] = 999; return true; });
    echo "$name: filtered=" . json_encode(array_values($out->toArray()))
        . " source=" . json_encode(array_values($j->toArray())) . "\n";
}

echo "\n== predicate deletes \$this[\$k] ==\n";
foreach ($types as $name => $t) {
    $j = new Judy($t);
    $stringKeyed = str_starts_with($name, 'STRING_');
    fill($j, $stringKeyed);
    // The element the predicate accepted is still copied: it existed when the
    // predicate ran. The source ends up empty.
    $out = $j->filter(function ($v, $k) use ($j) { unset($j[$k]); return true; });
    echo "$name: filtered=" . count($out) . " source=" . count($j) . "\n";
}

echo "\n== mixed payloads survive the copy ==\n";
foreach (['INT_TO_MIXED' => Judy::INT_TO_MIXED,
          'STRING_TO_MIXED' => Judy::STRING_TO_MIXED,
          'STRING_TO_MIXED_HASH' => Judy::STRING_TO_MIXED_HASH,
          'STRING_TO_MIXED_ADAPTIVE' => Judy::STRING_TO_MIXED_ADAPTIVE] as $name => $t) {
    $j = new Judy($t);
    $stringKeyed = str_starts_with($name, 'STRING_');
    $keys = $stringKeyed ? ['k1', 'k2', 'k3'] : [0, 1, 2];
    $j[$keys[0]] = ['nested' => [1, 2, 3]];
    $j[$keys[1]] = 'a string value';
    $j[$keys[2]] = 3.5;
    // Delete every entry from inside the predicate: the copies must not be
    // affected by the source's zvals being destroyed.
    $out = $j->filter(function ($v, $k) use ($j) { unset($j[$k]); return true; });
    echo "$name: " . json_encode($out->toArray()) . "\n";
}

echo "\n== BITSET ==\n";
$b = new Judy(Judy::BITSET);
foreach ([2, 4, 7, 11] as $i) { $b[$i] = true; }
$out = $b->filter(fn($v, $k) => $k % 2 === 0);
echo "BITSET: " . json_encode($out->toArray()) . "\n";

echo "\n== predicate returning false keeps result empty ==\n";
$j = new Judy(Judy::STRING_TO_INT_HASH);
fill($j, true);
echo "count=" . count($j->filter(fn($v, $k) => false)) . "\n";
?>
--EXPECT--
== plain predicate ==
INT_TO_INT: {"1":20,"2":30,"3":40}
INT_TO_MIXED: {"1":20,"2":30,"3":40}
INT_TO_PACKED: {"1":20,"2":30,"3":40}
STRING_TO_INT: {"bb":20,"ccc":30,"zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz":40}
STRING_TO_MIXED: {"bb":20,"ccc":30,"zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz":40}
STRING_TO_INT_HASH: {"bb":20,"ccc":30,"zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz":40}
STRING_TO_MIXED_HASH: {"bb":20,"ccc":30,"zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz":40}
STRING_TO_INT_ADAPTIVE: {"bb":20,"ccc":30,"zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz":40}
STRING_TO_MIXED_ADAPTIVE: {"bb":20,"ccc":30,"zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz":40}

== predicate mutates $this[$k] in place ==
INT_TO_INT: filtered=[10,20,30,40] source=[999,999,999,999]
INT_TO_MIXED: filtered=[10,20,30,40] source=[999,999,999,999]
INT_TO_PACKED: filtered=[10,20,30,40] source=[999,999,999,999]
STRING_TO_INT: filtered=[10,20,30,40] source=[999,999,999,999]
STRING_TO_MIXED: filtered=[10,20,30,40] source=[999,999,999,999]
STRING_TO_INT_HASH: filtered=[10,20,30,40] source=[999,999,999,999]
STRING_TO_MIXED_HASH: filtered=[10,20,30,40] source=[999,999,999,999]
STRING_TO_INT_ADAPTIVE: filtered=[10,20,30,40] source=[999,999,999,999]
STRING_TO_MIXED_ADAPTIVE: filtered=[10,20,30,40] source=[999,999,999,999]

== predicate deletes $this[$k] ==
INT_TO_INT: filtered=4 source=0
INT_TO_MIXED: filtered=4 source=0
INT_TO_PACKED: filtered=4 source=0
STRING_TO_INT: filtered=4 source=0
STRING_TO_MIXED: filtered=4 source=0
STRING_TO_INT_HASH: filtered=4 source=0
STRING_TO_MIXED_HASH: filtered=4 source=0
STRING_TO_INT_ADAPTIVE: filtered=4 source=0
STRING_TO_MIXED_ADAPTIVE: filtered=4 source=0

== mixed payloads survive the copy ==
INT_TO_MIXED: [{"nested":[1,2,3]},"a string value",3.5]
STRING_TO_MIXED: {"k1":{"nested":[1,2,3]},"k2":"a string value","k3":3.5}
STRING_TO_MIXED_HASH: {"k1":{"nested":[1,2,3]},"k2":"a string value","k3":3.5}
STRING_TO_MIXED_ADAPTIVE: {"k1":{"nested":[1,2,3]},"k2":"a string value","k3":3.5}

== BITSET ==
BITSET: [2,4]

== predicate returning false keeps result empty ==
count=0
